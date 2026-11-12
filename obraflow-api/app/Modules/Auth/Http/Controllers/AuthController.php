<?php

namespace App\Modules\Auth\Http\Controllers;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\PersonalAccessToken;
use App\Models\TenantUser;
use App\Models\User;
use App\Modules\Audit\AuditEvent;
use App\Modules\Audit\AuditLogger;
use App\Modules\Auth\Http\Requests\ForgotPasswordRequest;
use App\Modules\Auth\Http\Requests\LoginRequest;
use App\Modules\Auth\Http\Requests\LogoutOthersRequest;
use App\Modules\Auth\Http\Requests\ResetPasswordRequest;
use App\Modules\Auth\Services\MePayload;
use App\Modules\Shared\Exceptions\ApiException;
use App\Modules\Tenancy\ActiveTenantResolver;
use App\Modules\Tenancy\TenantSelector;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class AuthController extends Controller
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly MePayload $mePayload,
        private readonly TenantSelector $selector,
        private readonly ActiveTenantResolver $resolver,
    ) {}

    /** Login por senha: SOMENTE sessão (SPA). Integrações usam tokens emitidos por um administrador. */
    public function login(LoginRequest $request): JsonResponse
    {
        if (! $request->hasSession()) {
            throw ApiException::forbidden('stateful_required', 'O login por senha só está disponível para o aplicativo web (sessão por cookie).');
        }

        $email = Str::lower(trim($request->validated('email')));
        $password = $request->validated('password');

        /** @var User|null $user */
        $user = User::query()->where('email', $email)->first();

        // Sempre executa uma verificação de hash (tempo parecido com e sem usuário).
        $valid = Hash::check($password, $user?->password ?? self::dummyHash());

        if (! $user || ! $valid) {
            $this->audit->recordBestEffort(AuditEvent::AUTH_LOGIN_FAILED, userId: $user?->getKey(), metadata: [
                'email_hmac' => AuditLogger::emailHmac($email), 'reason' => 'invalid_credentials',
            ]);

            throw new ApiException(401, 'invalid_credentials', 'E-mail ou senha inválidos.');
        }

        if (! $user->isActive()) {
            $this->audit->recordBestEffort(AuditEvent::AUTH_LOGIN_FAILED, userId: $user->getKey(), metadata: [
                'email_hmac' => AuditLogger::emailHmac($email), 'reason' => 'account_blocked',
            ]);

            throw ApiException::forbidden('account_blocked', 'Esta conta está bloqueada.');
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        $user->forceFill(['last_login_at' => now()])->save();

        $this->audit->recordBestEffort(AuditEvent::AUTH_LOGIN, userId: $user->getKey());

        // Um único vínculo ativo ⇒ seleção automática (audita tenant.selected).
        $active = TenantUser::query()->with('tenant')
            ->where('user_id', $user->getKey())->where('membership_status', MembershipStatus::Active->value)->get()
            ->filter(fn (TenantUser $m) => $m->tenant?->allowsAccess());

        if ($active->count() === 1) {
            try {
                $this->selector->select($request, $user, $active->first()->tenant->uuid);
            } catch (Throwable $e) {
                // A seleção é evento de AUTORIZAÇÃO (falha fechada: sem auditoria, sem tenant). O login em si é melhor
                // esforço: continua SEM tenant ativo e o usuário escolhe explicitamente (que também exige auditoria).
                Log::error('Seleção automática de tenant falhou no login', ['exception' => $e::class]);
            }
        }

        return response()->json($this->mePayload->build($request, $user));
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $this->audit->recordBestEffort(AuditEvent::TOKEN_REVOKED, tenantId: $token->tenant_id, userId: $user->getKey(),
                entityType: 'token', entityId: $token->getKey(), metadata: ['token_id' => $token->getKey(), 'reason' => 'logout']);
            $token->delete();
        } elseif ($request->hasSession()) {
            $this->audit->recordBestEffort(AuditEvent::AUTH_LOGOUT, tenantId: $request->session()->get(ActiveTenantResolver::SESSION_KEY), userId: $user->getKey());
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json($this->mePayload->build($request, $request->user()));
    }

    /** Vínculos ATIVOS do usuário (para a tela de seleção de tenant). */
    public function tenants(Request $request): JsonResponse
    {
        return response()->json(['tenants' => $this->mePayload->build($request, $request->user())['tenants']]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $email = Str::lower(trim($request->validated('email')));

        Password::sendResetLink(['email' => $email]);

        $this->audit->recordBestEffort(AuditEvent::AUTH_PASSWORD_RESET_REQUESTED, metadata: ['email_hmac' => AuditLogger::emailHmac($email)]);

        // Resposta idêntica exista ou não a conta (evita enumeração).
        return response()->json(['message' => 'Se o e-mail estiver cadastrado, enviaremos as instruções de recuperação.'], 202);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token') + ['email' => Str::lower(trim($request->input('email')))],
            function (User $user, string $password): void {
                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                    $user->tokens()->delete(); // revoga tokens: senha comprometida
                    $this->audit->record(AuditEvent::AUTH_PASSWORD_CHANGED, userId: $user->getKey(), entityType: 'user', entityId: $user->getKey(), metadata: ['via' => 'reset', 'tokens_revoked' => true]);
                });
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ApiException::unprocessable('invalid_reset_token', 'O link de recuperação é inválido ou expirou.');
        }

        return response()->json(['message' => 'Senha redefinida. Entre com a nova senha.']);
    }

    /**
     * "Sair de outros dispositivos": o guard regrava a senha (novo hash) e atualiza o hash da sessão ATUAL;
     * as demais sessões caem na próxima requisição (AuthenticateSession do Sanctum).
     */
    public function logoutOthers(LogoutOthersRequest $request): JsonResponse
    {
        if (! $request->hasSession() || $this->resolver->isTokenRequest($request->user())) {
            throw ApiException::forbidden('stateful_required', 'Disponível apenas para sessões de navegador.');
        }

        $user = $request->user();

        DB::transaction(function () use ($request, $user): void {
            Auth::guard('web')->logoutOtherDevices($request->input('password'));
            $this->audit->record(AuditEvent::AUTH_SESSIONS_REVOKED, userId: $user->getKey(), entityType: 'user', entityId: $user->getKey());
        });

        return response()->json(['message' => 'Outras sessões foram encerradas.']);
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= Hash::make(Str::random(32));
    }
}
