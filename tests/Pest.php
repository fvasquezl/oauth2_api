<?php

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LaravelJsonApi\Testing\MakesJsonApiRequests;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class, MakesJsonApiRequests::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function jsonData(Model $model): array
{
    $type = Str::plural(Str::kebab(class_basename($model)));
    $exclude = ['id', 'created_at', 'updated_at', 'deleted_at'];
    $attributes = getModelAttributes($model, $exclude);
    $relationships = getModelRelationships($model);

    $data = [
        'type' => $type,
        'attributes' => $attributes,
    ];

    if ($relationships) {
        $data['relationships'] = $relationships;
    }

    if ($model->exists) {
        $data['id'] = (string) $model->getRouteKey();
    }

    return $data;
}

function getModelAttributes(Model $model, array $exclude = []): array
{
    return collect($model->getAttributes())
        ->except($exclude)
        ->filter(fn ($value, $key) => ! str_ends_with($key, '_id'))
        ->toArray();
}

function getModelRelationships(Model $model): array
{
    $relations = modelRelationNames($model);

    if (empty($relations)) {
        return [];
    }

    return collect($relations)
        ->mapWithKeys(function (string $relation) use ($model): array {
            $related = $model->$relation;

            if ($related === null || ! ($related instanceof Model)) {
                return [$relation => ['data' => ['type' => '', 'id' => null]]];
            }

            $typeMap = property_exists($model, 'jsonApiTypes') ? $model->jsonApiTypes : [];

            $jsonApiType = $typeMap[$relation] ?? Str::plural(Str::kebab(class_basename($related)));

            return [
                $jsonApiType => [
                    'data' => [
                        'type' => $jsonApiType,
                        'id' => (string) $related->getRouteKey(),
                    ],
                ],
            ];
        })
        ->filter(fn ($rel) => $rel['data']['id'] !== null)
        ->toArray();
}

function modelRelationNames(Model $model): array
{
    $reflection = new ReflectionClass($model);

    return collect($reflection->getMethods(ReflectionMethod::IS_PUBLIC))
        ->filter(fn ($method) => $method->class === get_class($model) &&
            $method->getNumberOfParameters() === 0 &&
            is_a($method->invoke($model), Relation::class))
        ->map(fn ($method) => $method->getName())
        ->values()
        ->all();
}

function userWithPermission(string $permission, ?User $user = null): User
{
    $user ??= User::factory()->create();

    $user->givePermissionTo(Permission::findOrCreate($permission));

    return $user;
}
