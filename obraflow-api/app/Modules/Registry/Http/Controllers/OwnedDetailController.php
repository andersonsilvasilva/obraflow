<?php

namespace App\Modules\Registry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\Supplier;
use App\Modules\Registry\Http\Requests\AddressRequest;
use App\Modules\Registry\Http\Requests\ContactRequest;
use App\Modules\Registry\Http\Resources\AddressResource;
use App\Modules\Registry\Http\Resources\ContactResource;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endereços e contatos de empresa, filial, cliente e fornecedor (tabelas dedicadas, D-P12).
 * A rota informa o dono (`owner_type` via defaults) e a permissão (middleware `can:` na rota).
 * O dono é resolvido no escopo do tenant ativo e o detalhe é buscado PELA RELAÇÃO do dono:
 * id de endereço/contato de outro dono (ou de outro tenant) responde 404.
 */
class OwnedDetailController extends Controller
{
    /** owner_type => [modelo, coluna de identificação na URL] */
    private const OWNERS = [
        'companies' => [Company::class, 'uuid'],
        'branches' => [CompanyBranch::class, 'id'],
        'customers' => [Customer::class, 'uuid'],
        'suppliers' => [Supplier::class, 'uuid'],
    ];

    public function __construct(private readonly RegistryService $registry) {}

    // ------------------------------------------------------------ endereços

    public function addresses(Request $request, string $owner): JsonResponse
    {
        $items = $this->owner($request, $owner)->addresses()->orderBy('id')->get();

        return response()->json(['data' => AddressResource::collection($items)->resolve($request)]);
    }

    public function storeAddress(AddressRequest $request, string $owner): JsonResponse
    {
        $relation = $this->owner($request, $owner)->addresses();
        $address = $relation->make();
        $data = $request->validated() + ['kind' => 'main'];
        $this->guardSingleMain($relation, $data['kind'], null);

        $this->registry->create($address, $data, $request->user());

        return response()->json(['data' => new AddressResource($address)], 201);
    }

    public function updateAddress(AddressRequest $request, string $owner, int $id): JsonResponse
    {
        $relation = $this->owner($request, $owner)->addresses();
        $address = (clone $relation)->findOrFail($id);
        $this->guardSingleMain($relation, $request->validated('kind', $address->kind), $address->id);

        $this->registry->update($address, $request->validated(), $request->user());

        return response()->json(['data' => new AddressResource($address)]);
    }

    public function destroyAddress(Request $request, string $owner, int $id): JsonResponse
    {
        $this->registry->delete($this->owner($request, $owner)->addresses()->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ contatos

    public function contacts(Request $request, string $owner): JsonResponse
    {
        $items = $this->owner($request, $owner)->contacts()->orderByDesc('is_primary')->orderBy('name')->get();

        return response()->json(['data' => ContactResource::collection($items)->resolve($request)]);
    }

    public function storeContact(ContactRequest $request, string $owner): JsonResponse
    {
        $relation = $this->owner($request, $owner)->contacts();
        $contact = $this->registry->create($relation->make(), $request->validated(), $request->user(),
            fn (Model $c) => $this->demoteOtherPrimary($relation, $c));

        return response()->json(['data' => new ContactResource($contact)], 201);
    }

    public function updateContact(ContactRequest $request, string $owner, int $id): JsonResponse
    {
        $relation = $this->owner($request, $owner)->contacts();
        $contact = $this->registry->update((clone $relation)->findOrFail($id), $request->validated(), $request->user(),
            fn (Model $c) => $this->demoteOtherPrimary($relation, $c));

        return response()->json(['data' => new ContactResource($contact)]);
    }

    public function destroyContact(Request $request, string $owner, int $id): JsonResponse
    {
        $this->registry->delete($this->owner($request, $owner)->contacts()->findOrFail($id), $request->user());

        return response()->json(null, 204);
    }

    // ------------------------------------------------------------ apoio

    private function owner(Request $request, string $key): Company|CompanyBranch|Customer|Supplier
    {
        [$model, $column] = self::OWNERS[$request->route('owner_type')];

        if ($column === 'id' && ! ctype_digit($key)) {
            throw ApiException::notFound();
        }

        return $model::query()->where($column, $key)->firstOrFail();
    }

    /** No máximo um endereço principal por dono: 409 explícito (o índice único do banco é a última defesa). */
    private function guardSingleMain(HasMany $relation, string $kind, ?int $ignoreId): void
    {
        if ($kind !== 'main') {
            return;
        }

        $exists = (clone $relation)->where('kind', 'main')->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))->exists();
        if ($exists) {
            throw ApiException::conflict('main_address_exists', 'Já existe um endereço principal. Altere-o ou use outro tipo.');
        }
    }

    /** Marcar um contato como principal rebaixa o anterior na mesma transação (no máximo um principal). */
    private function demoteOtherPrimary(HasMany $relation, Model $contact): void
    {
        if (! $contact->is_primary) {
            return;
        }

        (clone $relation)->where('is_primary', true)->when($contact->exists, fn ($q) => $q->whereKeyNot($contact->getKey()))
            ->get()->each(fn (Model $other) => $other->forceFill(['is_primary' => false])->save());
    }
}
