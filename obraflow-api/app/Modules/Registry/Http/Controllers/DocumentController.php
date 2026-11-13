<?php

namespace App\Modules\Registry\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\CompanyBranch;
use App\Models\Customer;
use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\DocumentLink;
use App\Models\DocumentVersion;
use App\Models\Supplier;
use App\Modules\Registry\Http\Controllers\Concerns\ListsRecords;
use App\Modules\Registry\Http\Requests\DocumentCategoryRequest;
use App\Modules\Registry\Http\Requests\DocumentLinkRequest;
use App\Modules\Registry\Http\Requests\DocumentPermissionsRequest;
use App\Modules\Registry\Http\Requests\DocumentRequest;
use App\Modules\Registry\Http\Requests\DocumentVersionRequest;
use App\Modules\Registry\Http\Resources\DocumentResource;
use App\Modules\Registry\Services\DocumentAccess;
use App\Modules\Registry\Services\DocumentStorage;
use App\Modules\Registry\Services\RegistryService;
use App\Modules\Shared\Exceptions\ApiException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Documentos (metadados, versões, URL assinada, vínculos e permissões) e categorias.
 * Rotas exigem `documents.view` (escritas de categoria/criação: `documents.manage`); o resto é decidido
 * documento a documento pelo DocumentAccess. Documento que o usuário não pode ver → 404.
 */
class DocumentController extends Controller
{
    use ListsRecords;

    /** linkable_type => [modelo, coluna da chave pública] */
    private const LINK_TARGETS = [
        'company' => [Company::class, 'uuid'],
        'company_branch' => [CompanyBranch::class, 'id'],
        'customer' => [Customer::class, 'uuid'],
        'supplier' => [Supplier::class, 'uuid'],
    ];

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DocumentAccess $access,
        private readonly RegistryService $registry,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $query = $this->access->visible(Document::query()->with('currentVersion'), $request->user())->orderByDesc('id');
        $this->search($query, $request, ['title']);

        foreach (['status', 'visibility', 'category_id'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('linkable_type') && $request->filled('linkable_key')) {
            $owner = $this->linkTarget((string) $request->query('linkable_type'), (string) $request->query('linkable_key'), notFound: false);
            $query->whereIn('id', DocumentLink::query()->select('document_id')
                ->where('linkable_type', $request->query('linkable_type'))->where('linkable_id', $owner?->getKey() ?? 0));
        }

        return $this->paginated($query, $request, DocumentResource::class);
    }

    public function store(DocumentRequest $request): JsonResponse
    {
        $data = $request->safe()->except('file');

        $document = DB::transaction(function () use ($request, $data) {
            $document = $this->storage->create($data, $request->user());
            if ($request->hasFile('file')) {
                $this->storage->storeVersion($document, $request->file('file'), $request->user());
            }

            return $document;
        });

        return response()->json(['data' => new DocumentResource($document->load(['currentVersion', 'versions']))], 201);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        $document = $this->find($request, $uuid, 'view')->load(['currentVersion', 'versions', 'links']);
        if ($this->access->allows($request->user(), $document, 'manage')) {
            $document->load('permissions');
        }

        return response()->json(['data' => new DocumentResource($document)]);
    }

    /** Título/categoria/status exigem `edit`; mudar a visibilidade exige `manage` (muda quem enxerga). */
    public function update(DocumentRequest $request, string $uuid): JsonResponse
    {
        $data = $request->validated();
        $document = $this->find($request, $uuid, array_key_exists('visibility', $data) ? 'manage' : 'edit');

        $this->storage->update($document, $data, $request->user());

        return response()->json(['data' => new DocumentResource($document->load('currentVersion'))]);
    }

    public function storeVersion(DocumentVersionRequest $request, string $uuid): JsonResponse
    {
        $document = $this->find($request, $uuid, 'edit');
        $this->storage->storeVersion($document, $request->file('file'), $request->user());

        return response()->json(['data' => new DocumentResource($document->load(['currentVersion', 'versions']))], 201);
    }

    /** URL assinada de curta duração para baixar a versão `number`. */
    public function url(Request $request, string $uuid, int $number): JsonResponse
    {
        $document = $this->find($request, $uuid, 'download');
        $version = DocumentVersion::query()->where('document_id', $document->id)->where('version_number', $number)->firstOrFail();

        return response()->json(['data' => [
            'url' => $this->storage->temporaryUrl($version, $request->user()),
            'expires_in_minutes' => (int) config('obraflow.documents.signed_url_minutes'),
        ]]);
    }

    public function link(DocumentLinkRequest $request, string $uuid): JsonResponse
    {
        $document = $this->find($request, $uuid, 'edit');
        $owner = $this->linkTarget($request->validated('linkable_type'), $request->validated('linkable_key'));
        $link = $this->storage->link($document, $owner, $request->user());

        return response()->json(['data' => ['id' => $link->id, 'linkable_type' => $link->linkable_type, 'linkable_id' => $link->linkable_id]], 201);
    }

    public function unlink(Request $request, string $uuid, int $linkId): JsonResponse
    {
        $document = $this->find($request, $uuid, 'edit');
        $this->storage->unlink($document, DocumentLink::query()->where('document_id', $document->id)->findOrFail($linkId), $request->user());

        return response()->json(null, 204);
    }

    public function permissions(DocumentPermissionsRequest $request, string $uuid): JsonResponse
    {
        $document = $this->find($request, $uuid, 'manage');
        $this->storage->syncPermissions($document, $request->validated('permissions'), $request->user());

        return response()->json(['data' => new DocumentResource($document->load('permissions'))]);
    }

    // ------------------------------------------------------------ categorias

    public function categories(): JsonResponse
    {
        return response()->json(['data' => DocumentCategory::query()->orderBy('name')->get(['id', 'parent_id', 'code', 'name'])]);
    }

    public function storeCategory(DocumentCategoryRequest $request): JsonResponse
    {
        $category = $this->registry->create(new DocumentCategory, $request->validated(), $request->user());

        return response()->json(['data' => $category->only(['id', 'parent_id', 'code', 'name'])], 201);
    }

    public function updateCategory(DocumentCategoryRequest $request, int $id): JsonResponse
    {
        $category = DocumentCategory::query()->findOrFail($id);
        $parentId = $request->validated('parent_id', $category->parent_id);

        // Impede ciclos: subindo a partir do novo pai, não pode encontrar a própria categoria.
        for ($cursor = $parentId, $guard = 0; $cursor !== null && $guard < 100; $guard++) {
            if ((int) $cursor === $category->id) {
                throw ApiException::unprocessable('category_cycle', 'A categoria não pode ser descendente de si mesma.');
            }
            $cursor = DocumentCategory::query()->whereKey($cursor)->value('parent_id');
        }

        $this->registry->update($category, $request->validated(), $request->user());

        return response()->json(['data' => $category->only(['id', 'parent_id', 'code', 'name'])]);
    }

    // ------------------------------------------------------------ apoio

    private function find(Request $request, string $uuid, string $ability): Document
    {
        $document = Document::query()->where('uuid', $uuid)->firstOrFail();

        if (! $this->access->allows($request->user(), $document, 'view')) {
            throw ApiException::notFound(); // não revela documento restrito
        }
        if (! $this->access->allows($request->user(), $document, $ability)) {
            throw ApiException::forbidden('forbidden', 'Você não tem permissão para esta ação.');
        }

        return $document;
    }

    private function linkTarget(string $type, string $key, bool $notFound = true): ?Model
    {
        [$model, $column] = self::LINK_TARGETS[$type] ?? [null, null];
        if ($model === null || ($column === 'id' && ! ctype_digit($key))) {
            return $notFound ? throw ApiException::notFound() : null;
        }

        $owner = $model::query()->where($column, $key)->first();
        if ($owner === null && $notFound) {
            throw ApiException::notFound();
        }

        return $owner;
    }
}
