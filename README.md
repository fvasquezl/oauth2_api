# oauth2_api

API REST construida con Laravel 13 + Passport 13 como servidor OAuth2. Proyecto de aprendizaje para dominar OAuth2, JSON API y Laravel Permissions en conjunto.

## Stack

| Capa | Tecnología |
|------|------------|
| Framework | Laravel 13.7 |
| PHP | 8.5 (vía Sail / Ubuntu 24.04) |
| Base de datos | MySQL 8.4 |
| Autenticación | Laravel Passport 13 (OAuth2) |
| Testing | Pest 4 |
| Contenedor | Laravel Sail (Docker) |

## Dependencias instaladas

### Producción

| Paquete | Versión | Cómo llegó | Para qué |
|---------|---------|------------|----------|
| `laravel/passport` | ^13.0 | `artisan install:api --passport` | Servidor OAuth2 — emite y valida tokens |
| `laravel-json-api/laravel` | v5.2.1 | `composer require` | JSON API spec: servidor, schemas, rutas versionadas |
| `spatie/laravel-permission` | v7.4 | `composer require` | Sistema de permisos y roles |
| `league/oauth2-server` | transitivo | dependencia de Passport | Motor OAuth2 (authorization codes, tokens, scopes) |
| `lcobucci/jwt` | 5.6.0 | transitivo vía `league/oauth2-server` | Generación y validación de JWT |
| `lcobucci/clock` | transitivo | dependencia de lcobucci/jwt | Abstracción de tiempo para JWT |
| `laravel-json-api/core` | v5.3.0 | transitivo | Núcleo de laravel-json-api |
| `laravel-json-api/eloquent` | v4.7.0 | transitivo | Integración con Eloquent ORM |
| `laravel-json-api/validation` | v4.4.0 | transitivo | Validación según JSON API spec |
| `laravel-json-api/exceptions` | v3.3.0 | transitivo | Manejo de errores formato JSON API |

> **Nota sobre `lcobucci/jwt`:** no aparece en la documentación oficial de Passport porque no es dependencia directa — viene por la cadena `laravel/passport → league/oauth2-server → lcobucci/jwt`. No hace falta declararlo en `composer.json`.

### Desarrollo

| Paquete | Para qué |
|---------|----------|
| `pestphp/pest` | Framework de testing |
| `laravel-json-api/testing` | v3.2.0 — helpers `$this->jsonApi()->withData()->post()` en tests |
| `laravel/sail` | Entorno Docker |
| `laravel/pint` | Formateo de código (PHP-CS-Fixer) |
| `laravel/pail` | Log tailer en tiempo real |

## Extensiones PHP requeridas

| Extensión | Estado en Sail | Nota |
|-----------|---------------|------|
| `sodium` | ✅ Disponible | Compilada en el binario PHP 8.5 del PPA ondrej/php. No aparece como paquete separado en el Dockerfile pero está presente. Verificable con: `./vendor/bin/sail php -r "echo extension_loaded('sodium') ? 'OK' : 'MISSING';"` |
| `openssl` | ✅ Disponible | Requerida por Passport para signing keys |
| `mbstring`, `xml`, `zip`, `bcmath` | ✅ Disponible | Incluidas explícitamente en `docker/8.5/Dockerfile` |

## Cómo se instaló Passport

```bash
./vendor/bin/sail artisan install:api --passport
```

Este comando hace todo en una sola pasada:
1. Publica `routes/api.php`
2. Instala `laravel/passport` vía Composer (y sus dependencias transitivas)
3. Publica las migraciones de Passport a `database/migrations/`
4. Configura el guard `api` en `config/auth.php`
5. Genera las OAuth signing keys en `storage/oauth-private.key` y `storage/oauth-public.key`

## Migraciones de Passport

Publicadas localmente en `database/migrations/` (fecha 2026-05-11). Se editan aquí si el schema necesita cambios.

| Tabla | Propósito |
|-------|-----------|
| `oauth_auth_codes` | Códigos de autorización (Authorization Code flow) |
| `oauth_access_tokens` | Access tokens emitidos |
| `oauth_refresh_tokens` | Refresh tokens |
| `oauth_clients` | Aplicaciones registradas como clientes OAuth2 |
| `oauth_device_codes` | Device Authorization flow |

## Arquitectura de autenticación

```
Cliente → POST /oauth/token (con client_id + secret) → Passport emite JWT
Cliente → GET /api/v1/... (con Authorization: Bearer <token>) → Passport valida JWT → Controlador
```

- El guard `api` está configurado con `driver: passport` en `config/auth.php`
- El modelo `User` implementa `Laravel\Passport\Contracts\OAuthenticatable` y usa los traits `HasApiTokens` y `HasRoles`
- Los modelos usan PHP 8 attributes (`#[Fillable]`, `#[Hidden]`) en lugar de propiedades `$fillable`/`$hidden`

## JSON API

Las rutas de la API siguen la especificación [JSON:API v1.1](https://jsonapi.org/). El servidor V1 está en `app/JsonApi/V1/Server.php` con `baseUri = /api/v1`.

### Estructura

```
app/JsonApi/
└── V1/
    ├── Server.php                    ← punto de entrada, registra todos los schemas
    ├── Articles/
    │   ├── ArticleSchema.php         ← campos, relaciones y authorizer del recurso
    │   ├── ArticleRequest.php        ← reglas de validación
    │   └── ArticleAuthorizer.php     ← lógica de autorización por acción
    ├── Authors/
    │   └── AuthorSchema.php          ← schema para User con tipo JSON:API "authors"
    └── Categories/
        └── CategorySchema.php        ← schema para Category
```

### Schema como contrato de la API

**El paquete valida el documento JSON:API contra los schemas registrados ANTES de correr el authorizer, la validación y el controlador.** Si el payload referencia un tipo o relación que no existe en ningún schema registrado, retorna `400` inmediatamente — sin importar si el usuario está autenticado o no.

Orden real de ejecución dentro de `FormRequest::validateResolved()`:

```
Request HTTP
  │
  ├─ 1. prepareForValidation()          ← SPEC COMPLIANCE (primero)
  │       └─ validateResourceDocument()
  │             • ¿campo desconocido en attributes?  → 400
  │             • ¿tipo incorrecto en relación?      → 422
  │             • ¿recurso referenciado no existe?   → 422
  │
  ├─ 2. passesAuthorization()           ← AUTHORIZER (segundo)
  │       └─ ArticleAuthorizer::store()
  │             → false sin usuario     → 401
  │             → false sin permiso     → 403
  │
  └─ 3. rules() → validated()           ← VALIDACIÓN (tercero)
              → 422 si falla
              → retorna array con datos validados
                    ↓
            Store.php → ModelHydrator::hydrate($validatedData)
              ├─ fillAttributes()   → llena title, slug, content en el modelo
              ├─ fillRelationships() → llena user_id, category_id
              └─ persist()          → $model->save()
```

**Por esto algunos tests funcionan sin permiso:** `'relationship must be a valid type'` y `'can have protection to mass assignment'` son rechazados en el paso 1 (spec compliance), antes de que el authorizer corra. El usuario puede no tener permiso y aún así recibir el 400/422 correcto.

Por esto **todos los schemas deben estar completos y registrados** antes de que los tests de autorización funcionen. Un schema incompleto devuelve 400 antes del 401/403 esperado.

### Por qué `rules()` vacío rompe el guardado

`Store.php` pasa `$request->validated()` al hydrator:

```php
$model = $store->create($resourceType)->withRequest($query)->store($request->validated());
```

Laravel's `validated()` retorna **únicamente los campos presentes en `rules()`**. Con `rules()` retornando `[]`, `validated()` retorna `{}`, y `ModelHydrator::fillAttributes()` no tiene nada que llenar — el modelo se guarda con solo timestamps.

Las rutas se nombran automáticamente con el patrón `api.v1.{recurso}.{acción}`, por ejemplo:

| Ruta | Nombre |
|------|--------|
| `POST /api/v1/articles` | `api.v1.articles.store` |
| `GET /api/v1/articles` | `api.v1.articles.index` |
| `GET /api/v1/articles/{id}` | `api.v1.articles.show` |
| `PATCH /api/v1/articles/{id}` | `api.v1.articles.update` |
| `DELETE /api/v1/articles/{id}` | `api.v1.articles.destroy` |

### Cómo publicar los stubs de JSON:API (opcional)

```bash
./vendor/bin/sail artisan jsonapi:stubs
```

## Sistema de permisos

Usa `spatie/laravel-permission`. Las tablas se migraron en el batch 2 (2026-05-12).

- `User` usa el trait `HasRoles` — puede asignársele roles y permisos directos
- Los permisos se crean en los `beforeEach` de los tests con `Permission::findOrCreate('articles:store', 'api')`
- El guard usado es `api` — configurado vía `AUTH_GUARD=api` en `.env` y leído en `config/auth.php` como guard por defecto

### Migraciones de permisos

| Tabla | Propósito |
|-------|-----------|
| `permissions` | Permisos registrados (ej. `articles:store`) |
| `roles` | Roles disponibles |
| `model_has_permissions` | Permisos asignados directamente a un modelo |
| `model_has_roles` | Roles asignados a un modelo |
| `role_has_permissions` | Permisos que tiene cada rol |

## Autorización — flujo completo

La autorización combina tres capas: middleware de Passport, un Authorizer de JSON:API y una Policy de Laravel.

```
Request POST /api/v1/articles
    → middleware auth:api          (Passport — 401 si no hay token)
    → ArticleAuthorizer::store()   (JSON:API — delega a Gate)
    → Gate::inspect('create', Article::class)
    → ArticlePolicy::create()      (lógica de permiso — 403 si falla)
    → ArticleRequest::rules()      (validación del payload — 422 si falla)
    → JsonApiController::store()   (persiste)
```

### Sin middleware `auth:api`

El middleware `auth:api` no es estrictamente necesario cuando hay un Authorizer configurado. El paquete `laravel-json-api` distingue automáticamente entre guest y usuario autenticado en `failedAuthorization()`:

```php
// vendor/laravel-json-api/laravel/src/Http/Requests/FormRequest.php
if ($auth->guest()) {
    throw new AuthenticationException();  // → 401
}
// usuario autenticado sin permiso → AuthorizationException → 403
```

Si el authorizer retorna `false` para un guest, el paquete lanza `AuthenticationException` (401), no 403. Por tanto el Authorizer + Policy cubren ambos casos solos.

### `ArticleAuthorizer`

Ubicación: `app/JsonApi/V1/Articles/ArticleAuthorizer.php`

Implementa `LaravelJsonApi\Contracts\Auth\Authorizer`. El método `store` hace un único short-circuit y delega el resto a la Policy:

```php
public function store(Request $request, string $modelClass): bool|Response
{
    $authorId = $request->json('data.relationships.authors.data.id');

    if ($authorId === null) {
        return true;  // authors ausente → deja que rules() lo rechace (422)
    }

    return Gate::inspect('create', $modelClass);
}
```

**Por qué esta lógica:**
- Si `authors` está ausente en el payload, el authorizer deja pasar — `rules()` lo atrapará con 422 (`required`). Si el authorizer rechazara aquí, el test `'authors is required'` devolvería 403 en vez de 422.
- La verificación de permiso y de que el autor coincida con el usuario autenticado la hace `ArticlePolicy::create()`.
- El check de guest (usuario no autenticado) lo maneja `failedAuthorization()` del paquete — si `Gate::inspect()` devuelve deny y el usuario es guest, lanza `AuthenticationException` → 401.

> **Nota:** se usa `'create'` como habilidad del Gate porque `ArticlePolicy` tiene un método `create()`. El mapeo automático `store → create` solo existe dentro de `$this->authorize()` en controladores — `Gate::inspect()` directo busca el método por nombre exacto.

### `ArticlePolicy`

Ubicación: `app/Policies/ArticlePolicy.php`

```php
public function create(User $user): bool
{
    return $user->hasPermissionTo('articles:store')
        && (string) $user->getRouteKey() === (string) request()->input('data.relationships.authors.data.id');
}
```

Combina dos checks en uno:
1. El usuario tiene el permiso `articles:store`.
2. El ID del autor en el payload coincide con el ID del usuario autenticado — impide crear artículos en nombre de otro usuario.

`request()->input('data.relationships.authors.data.id')` usa dot notation de Laravel para acceder al JSON body parseado. Funciona porque el JSON:API body es un array PHP anidado accesible vía `input()`.

> PHP short-circuits `&&` — si el permiso falla, el segundo check no se evalúa.

### Registrar el Authorizer en el Schema

El método `authorizer()` en el Schema **debe ser estático**. La clase base `LaravelJsonApi\Core\Schema\Schema` lo define como `static` — declararlo como instancia de método provoca comportamiento inconsistente:

```php
// app/JsonApi/V1/Articles/ArticleSchema.php
public static function authorizer(): string
{
    return ArticleAuthorizer::class;
}
```

### `ArticlePolicy`

Ubicación: `app/Policies/ArticlePolicy.php`

Auto-descubierta por Laravel 13 — no requiere registro manual en ningún ServiceProvider. Laravel asocia `ArticlePolicy` con `Article` por convención de nombres.

### `actingAs()` en tests con Passport

`$this->actingAs($user, 'api')` usa el guard directamente y puede dar comportamientos diferentes a producción. La forma correcta con Passport es:

```php
use Laravel\Passport\Passport;

Passport::actingAs($user);
```

Helpers globales en `tests/Pest.php`:

```php
function actingAs(User $user): void
{
    Passport::actingAs($user);
}

function userWithPermission(string $permission, User $user): User
{
    $user->givePermissionTo($permission);
    return $user;
}
```

## Testing con JSON:API

### Habilitar `$this->jsonApi()` en tests

El trait `MakesJsonApiRequests` de `laravel-json-api/testing` debe aplicarse al `TestCase` base para que todos los tests de Pest tengan acceso al helper `jsonApi()`:

```php
// tests/TestCase.php
use LaravelJsonApi\Testing\MakesJsonApiRequests;

abstract class TestCase extends BaseTestCase
{
    use MakesJsonApiRequests;
}
```

Sin este trait, los tests lanzan `Call to undefined method ::jsonApi()`.

### Helper `jsonData()`

Definido en `tests/Pest.php`. Convierte cualquier modelo Eloquent al formato JSON:API usando reflection para detectar atributos y relaciones automáticamente:

```php
$data = jsonData(Article::factory()->make());
// produce: ['type' => 'articles', 'attributes' => [...], 'relationships' => [...]]
```

> **Gotcha:** si el modelo no tiene atributos (factory vacía), `attributes` se serializa como `[]` (array JSON) en lugar de `{}` (objeto JSON), lo que viola el spec y produce el error `"The member attributes must be an object"`. La factory debe retornar los campos reales del modelo.

### Orden de middleware y schemas

Sin `auth:api` en las rutas, el authorizer puede manejar el 401 solo — **siempre y cuando todos los schemas estén completos**. Si el documento referencia tipos/relaciones no registradas en los schemas, el paquete devuelve 400 antes de llegar al authorizer.

Verificado en esta sesión: con `CategorySchema`, `AuthorSchema` y `ArticleSchema` completos (incluyendo relaciones), el test de guest retorna 401 sin necesitar `auth:api` en las rutas.

### `AuthorSchema` — User expuesto como tipo `authors`

El modelo `User` se expone en la API bajo el tipo JSON:API `authors` a través de `AuthorSchema`. El nombre del schema determina el tipo — al llamarse `AuthorSchema` en el namespace `Authors`, el paquete deriva el tipo `authors` automáticamente.

```
app/JsonApi/V1/Authors/AuthorSchema.php → tipo JSON:API: "authors"
                                        → modelo: App\Models\User
```

> **Gotcha del comando `jsonapi:schema`:** el flag `-m` es case-sensitive y requiere el nombre exacto del modelo. `-m users` genera `App\Models\users` (minúsculas, inexistente). La forma correcta es `-m User`:
> ```bash
> ./vendor/bin/sail artisan jsonapi:schema authors -m User
> ```

### `$jsonApiTypes` en el modelo

El helper `jsonData()` en `tests/Pest.php` usa reflection para detectar relaciones. Para que use el nombre correcto en el payload JSON:API (en lugar del nombre del método Eloquent), el modelo define un mapa:

```php
// app/Models/Article.php
public array $jsonApiTypes = ['user' => 'authors'];
```

Esto indica que el método `user()` del modelo debe representarse como tipo `authors` en el payload. Sin este mapa, `jsonData()` generaría `relationships.users`, que no es una relación reconocida por el `ArticleSchema`.

> **Importante:** no duplicar el método con otro nombre (e.g. agregar `authors()` además de `user()`). El helper detecta todos los métodos públicos sin parámetros que retornan una Relation — tendría dos entradas para el mismo modelo y generaría `users` y `authors` simultáneamente.

### `ID::make()` y UUIDs — gotcha crítico

El patrón por defecto de `ID::make()` en un schema es `[0-9]+` (solo enteros). Cuando el modelo usa `HasUuids`, los IDs tienen formato `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`. La cadena UUID **no hace match** con el patrón numérico, y el spec validator asume que el recurso no existe:

```
spec->exists('authors', '019e27f1-caca-724c-a79e-83f793aa95eb')
  → ID::match('019e27f1-...') → preg_match('/^[0-9]+$/') → false
  → retorna false sin consultar la DB
  → "The related resource does not exist." (404)
```

**Todo esto ocurre en el paso 1 (spec compliance), antes del authorizer.** El test falla con 404 aunque el usuario exista en la DB.

**Solución:** declarar el formato UUID en el schema del modelo que usa `HasUuids`:

```php
// app/JsonApi/V1/Authors/AuthorSchema.php
public function fields(): array
{
    return [
        ID::make()->uuid(),  // ← sin esto, UUIDs no pasan la validación de spec
        Str::make('name'),
        ...
    ];
}
```

`->uuid()` configura el patrón a `[\da-fA-F]{8}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{4}-[\da-fA-F]{12}`. También existe `->ulid()` para ULIDs y `->matchAs($regex)` para patrones custom.

> **Regla:** si el modelo usa `HasUuids` o `HasUlids`, su schema JSON:API **debe** tener `ID::make()->uuid()` o `ID::make()->ulid()`.

### Comandos para generar recursos JSON:API

```bash
# Crear schema para un modelo (flag -m es case-sensitive, usar nombre exacto del modelo)
./vendor/bin/sail artisan jsonapi:schema Article -m Article
./vendor/bin/sail artisan jsonapi:schema authors -m User   # tipo será "authors"

# Crear request de validación
./vendor/bin/sail artisan jsonapi:request Article --server=v1

# Crear schema + request en un solo comando
./vendor/bin/sail artisan jsonapi:requests Article --server=v1
```

## Comandos frecuentes

```bash
# Levantar el stack
./vendor/bin/sail up -d

# Correr migraciones
./vendor/bin/sail artisan migrate

# Generar OAuth signing keys (solo en instalación nueva)
./vendor/bin/sail artisan passport:keys

# Crear un cliente OAuth2 interactivo
./vendor/bin/sail artisan passport:client

# Correr tests
./vendor/bin/sail artisan test

# Correr un test específico
./vendor/bin/sail artisan test --filter=NombreDelTest

# Formatear código
./vendor/bin/sail composer pint
```

## Configuración de la base de datos de tests

`phpunit.xml` define `DB_DATABASE=testing`. El archivo `docker/mysql/create-testing-database.sh` se monta en el contenedor MySQL para crear esa base de datos automáticamente al iniciar Sail.

## UUIDs como primary key en User

El modelo `User` usa el trait `HasUuids` de Laravel, lo que genera UUIDs v7 como primary key en lugar de auto-increment. Esto requiere ajustes en cascada en varias migraciones.

### Cambios necesarios para soportar UUID en `users`

**1. Migración de `users`** — la columna primaria debe llamarse `id` con tipo UUID:

```php
// ❌ Incorrecto: crea columna llamada 'uuid', no 'id'
$table->uuid();

// ✅ Correcto
$table->uuid('id')->primary();
```

**2. Migración de `articles`** — la FK hacia `users` debe ser `foreignUuid`:

```php
// ❌ Incorrecto para UUID
$table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();

// ✅ Correcto
$table->foreignUuid('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
```

> `foreignUuid('user_id')->constrained()` referencia `users.id` por convención. Para que funcione, `users.id` debe existir como columna UUID (cambio del punto 1).

**3. Migración de permisos de Spatie** — `model_id` guarda el primary key del modelo que tiene el permiso. Si el modelo es `User` con UUID, la columna debe ser `char(36)`:

```php
// ❌ Incorrecto para UUID — BIGINT no puede guardar un UUID
$table->unsignedBigInteger($columnNames['model_morph_key']);

// ✅ Correcto — aparece 2 veces en la migración (model_has_permissions y model_has_roles)
$table->char($columnNames['model_morph_key'], 36);
```

> `char(36)` es más correcto que `string` (varchar) para UUIDs porque la longitud siempre es exactamente 36 — MySQL optimiza mejor índices y comparaciones en columnas de longitud fija.

Después de cualquier cambio en migraciones correr:

```bash
./vendor/bin/sail artisan migrate:fresh
```

## Generación de modelos con Blueprint

`laravel-shift/blueprint` está instalado y ya se ejecutó. El archivo `draft.yaml` generó:

| Archivo | Generado por Blueprint |
|---------|----------------------|
| `app/Models/Article.php` | ✅ con `category()` y `user()` BelongsTo |
| `app/Models/Category.php` | ✅ con `articles()` HasMany |
| `database/factories/ArticleFactory.php` | ✅ con `title`, `slug`, `content`, `Category::factory()`, `User::factory()` |
| `database/migrations/2026_05_12_225922_create_articles_table.php` | ✅ |
| `database/migrations/2026_05_12_225923_create_categories_table.php` | ✅ |

> **Gotcha — migración duplicada:** Blueprint generó una nueva migración `create_articles_table` pero ya existía la original vacía de 2026-05-11. `RefreshDatabase` corre `migrate:fresh` y falla con `Table 'articles' already exists`. Solución: eliminar la migración original vacía `2026_05_11_050851_create_articles_table.php`.

> **Gotcha — campo `approved` faltante:** aunque `draft.yaml` incluía `approved: boolean default:false`, Blueprint no lo generó en la migración. Hay que agregarlo manualmente: `$table->boolean('approved')->default(false)`.

> **Gotcha — `$guarded = []`:** Blueprint genera `$guarded = []` en los modelos (todo es asignable). El test de mass assignment manda `approved: true` y espera `400`. Para proteger `approved`, usar `$fillable` explícito o `$guarded = ['approved']`.

## Entorno de desarrollo

### Correr tests desde VS Code (Better Pest + Sail)

PHP Tools (Devsense) corre PHP localmente y no puede resolver el hostname `mysql` de Docker. La solución es usar la extensión **Better Pest** configurada para ejecutar dentro del contenedor Sail.

**Configuración en `.vscode/settings.json`:**

```json
{
    "better-pest.docker.enable": true,
    "better-pest.docker.command": "docker exec oauth2_api-laravel.test-1",
    "better-pest.docker.paths": {
        "/home/fvasquez/Code/SAIL/oauth2_api": "/var/www/html"
    }
}
```

Better Pest construye el comando: `docker exec oauth2_api-laravel.test-1 ./vendor/bin/pest --filter="..."`, que corre dentro del contenedor donde `mysql` resuelve correctamente.

**`.vscode/settings.json` completo:**

```json
{
    "better-pest.docker.enable": true,
    "better-pest.docker.command": "docker exec oauth2_api-laravel.test-1",
    "better-pest.docker.paths": {
        "/home/fvasquez/Code/SAIL/oauth2_api": "/var/www/html"
    },
    "php.testExplorer": false,
    "php.codeLens.enabled": false
}
```

- `php.testExplorer: false` — desactiva el test explorer de PHP Tools (evita errores de conexión a MySQL en background)
- `php.codeLens.enabled: false` — quita los botones ▶ inline junto a cada test

> Si `php.codeLens.enabled: false` deshabilita otras funciones útiles de PHP Tools (como go-to-definition), se puede volver a `true` y convivir con los botones rojos ignorándolos.

**Atajos de teclado** (`~/.config/Code/User/keybindings.json`):

| Atajo | Acción |
|-------|--------|
| `Ctrl+Shift+F10` | Test bajo el cursor |
| `Shift+Meta+F10` | Todos los tests del archivo |
| `Shift+F10` | Repetir último test |

> **Setting que NO existe en Better Pest 0.1.0:** `better-pest.phpExecutable` — no está en el `package.json` de esta versión. Usar el bloque `docker.*` en su lugar.

## Estado de los tests — `CreateArticlesTest.php`

| Test | Estado |
|------|--------|
| `guest users cannot create articles` | ✅ |
| `returns json errors when no data is sent` | ✅ |
| `authenticated users can create articles` | ✅ |
| `authenticated users cannot create articles without permissions` | ✅ |
| `authenticated users cannot create articles on behalf of other user` | ✅ |
| `can have protection to mass assignment` | ✅ |
| `authors is required` | ✅ |
| `categories is required` | ✅ |
| `relationship must be a valid type` (2 datasets) | ✅ |
| `rejects empty required attributes` (title, content) | ✅ |
| `slug must be unique` | ❌ 500 — falta `Rule::unique('articles','slug')` en `ArticleRequest` |
| `rejects invalid slugs` (5 variantes) | ❌ — faltan custom Rule classes y traducciones |

## Pendiente de implementar

- **`slug must be unique`** — agregar a `ArticleRequest::rules()`:
  ```php
  'slug' => ['required', 'string', ..., Rule::unique('articles', 'slug')->ignore($this->model()?->getKey())],
  ```

- **`rejects invalid slugs`** — crear reglas de validación custom para slug:
  - `app/Rules/NoUnderscores.php` — `$fail(__('validation.no_underscores', ['attribute' => $attribute]))`
  - `app/Rules/NoStartingDashes.php`
  - `app/Rules/NoEndingDashes.php`
  - Orden en `rules()`: custom rules → `'regex:/^[a-z0-9-]+$/'` → `Rule::unique()`

- **`lang/en/validation.php`** con las traducciones:
  ```php
  'no_underscores'     => 'The :attribute must not contain underscores.',
  'no_starting_dashes' => 'The :attribute must not start with a dash.',
  'no_ending_dashes'   => 'The :attribute must not end with a dash.',
  ```

> Las claves de relaciones en `rules()` son `'authors'` y `'categories'` (no `'relationships.authors'`) — el paquete aplana el documento JSON:API antes de llegar a `rules()`.
