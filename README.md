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
    ├── Server.php                    ← punto de entrada, registra schemas y baseUri
    └── Articles/
        ├── ArticleSchema.php         ← campos y relaciones del recurso
        └── ArticleRequest.php        ← reglas de validación
```

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
- Los permisos se crean en los `beforeEach` de los tests con `Permission::findOrCreate('articles:store', 'web')`
- El guard usado es `web` para los permisos de esta API

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

Implementa `LaravelJsonApi\Contracts\Auth\Authorizer`. El método `store` delega al Gate de Laravel:

```php
public function store(Request $request, string $modelClass): bool|Response
{
    return $request->user() ? Gate::inspect('create', $modelClass) : false;
}
```

> **Nota:** se usa `'create'` como habilidad del Gate porque `ArticlePolicy` tiene un método `create()`. El mapeo automático `store → create` solo existe dentro de `$this->authorize()` en controladores — `Gate::inspect()` directo busca el método por nombre exacto.

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

### Orden de middleware — autenticación antes de validación

Las rutas JSON:API deben tener `->middleware('auth:api')` aplicado. Sin él, el middleware de JSON:API valida el payload antes de que Passport verifique la autenticación, y requests sin token reciben `400` en lugar del `401` esperado.

```php
// routes/api.php
JsonApiRoute::server('v1')
    ->prefix('v1')
    ->middleware('auth:api')   // ← Passport intercepta primero
    ->resources(function (ResourceRegistrar $server) {
        $server->resource('articles', JsonApiController::class);
    });
```

### Comandos para generar recursos JSON:API

```bash
# Crear schema para un modelo
./vendor/bin/sail artisan jsonapi:schema Article --server=v1

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

## Generación de modelos con Blueprint

`laravel-shift/blueprint` está instalado. El archivo `draft.yaml` en la raíz define los modelos a generar:

```yaml
models:
  Article:
    title: string
    slug: string unique
    content: longtext
    category_id: id
    user_id: id
    approved: boolean default:false

  Category:
    name: string
    slug: string unique
    relationships:
      hasMany: Article
```

Para generar migraciones, modelos y factories a partir del draft:

```bash
./vendor/bin/sail artisan blueprint:build
```

> **Pendiente de decidir:** `category_id: id` genera una relación `BelongsTo` (un artículo, una categoría). Los tests usan `relationships.categories` en plural, que sugiere `BelongsToMany` con tabla pivot. Confirmar si es one-to-many o many-to-many antes de buildear.

## Pendiente de implementar

- Decidir y ajustar relación `Article ↔ Category` (BelongsTo vs BelongsToMany) en `draft.yaml`
- Ejecutar `blueprint:build` para generar migración, modelo y factory
- Corregir `ArticlePolicy::create()` — firma incorrecta (`$article` no existe en creación) y permiso debe ser `articles:store` no `articles:create`
- Completar `ArticleSchema` con campos `title`, `slug`, `content` + relaciones `authors` y `categories`
- Completar `ArticleRequest::rules()` con validaciones de campos y relaciones
- Agregar helpers `actingAs()` y `userWithPermission()` a `tests/Pest.php`
- Reglas de validación custom para slug: `no_underscores`, `no_starting_dashes`, `no_ending_dashes`
