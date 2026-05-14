<?php

use App\Models\Article;
use App\Models\User;
use Laravel\Passport\Passport;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    Permission::findOrCreate('articles:store', 'api');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

it('guest users cannot create articles', function () {
    $data = jsonData(
        Article::factory()->make(),
    );

    $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertUnauthorized(); // 401

    $this->assertDatabaseEmpty('articles');

});

it('returns json errors when no data is sent', function () {
    $user = User::factory()->create();

    Passport::actingAs($user);

    $this->jsonApi()
        ->withData([])
        ->post(route('api.v1.articles.store'))
        ->assertStatus(400)
        ->assertJson([
            'errors' => [
                ['source' => ['pointer' => '/data']],
            ],
        ]);
});

it('authenticated users can create articles', function () {
    $data = jsonData(
        $article = Article::factory()->make()
    );

    Passport::actingAs(userWithPermission('articles:store', $article->user));

    $response = $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertCreated();  // 201

    expect($response->json('data.attributes'))
        ->title->toBe($article->title)
        ->slug->toBe($article->slug)
        ->content->toBe($article->content);

    $this->assertDatabaseHas('articles', [
        'title' => $article->title,
        'slug' => $article->slug,
        'content' => $article->content,
        'user_id' => $article->user->id,
    ]);
});

it('authenticated users cannot create articles without permissions', function () {
    $data = jsonData(
        $article = Article::factory()->make()
    );

    Passport::actingAs($article->user);

    $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertForbidden();  // 403 Forbidden

    $this->assertDatabaseEmpty('articles');

});

it('authenticated users cannot create articles on behalf of other user', function () {
    $data = jsonData(
        $article = Article::factory()->make()
    );
    $data['relationships']['authors']['data']['id'] = User::factory()->create()->getRouteKey();

    Passport::actingAs(userWithPermission('articles:store', $article->user));

    $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertForbidden();  // 403

    $this->assertDatabaseEmpty('articles');
});

it('can have protection to mass assignment', function () {
    $data = jsonData(
        $article = Article::factory()->make()
    );

    $data['attributes']['approved'] = true;

    Passport::actingAs(userWithPermission('articles:store', $article->user));

    $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertStatus(400);
});

it('authors is required', function () {
    
    $data = jsonData(
        $article = Article::factory()->make(),
    );
    unset($data['relationships']['authors']);

    Passport::actingAs($article->user);

    $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertUnprocessable() // 422
        ->assertJsonFragment(['source' => ['pointer' => '/data/relationships/authors']]);
    $this->assertDatabaseMissing('articles', [
        $article->getRouteKeyName() => $article->getRouteKey(),
    ]);
});

it('categories is required', function () {
    $data = jsonData(
        $article = Article::factory()->make(),
    );
    unset($data['relationships']['categories']);

    Passport::actingAs(userWithPermission('articles:store', $article->user));

    $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertUnprocessable() // 422
        ->assertJsonFragment(['source' => ['pointer' => '/data/relationships/categories']]);
    $this->assertDatabaseMissing('articles', [
        $article->getRouteKeyName() => $article->getRouteKey(),
    ]);
});

it('relationship must be a valid type', function (string $relationship, string $wrongType) {
    $data = jsonData(
        $article = Article::factory()->make(),
    );

    $data['relationships'][$relationship] = [
        'data' => ['type' => $wrongType, 'id' => '1'],
    ];
   

    Passport::actingAs(userWithPermission('articles:store', $article->user));

    $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertUnprocessable()
        ->assertSee("data\\/relationships\\/$relationship");
})
    ->with([
        'authors with categories type' => ['authors', 'categories'],
        'categories with authors type' => ['categories', 'authors'],
    ]);

it('rejects empty required attributes', function (string $field) {
    $data = jsonData(
        $article = Article::factory()->make([$field => '']),
    );

    Passport::actingAs(userWithPermission('articles:store', $article->user));

    $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertUnprocessable()
        ->assertSee("data\\/attributes\\/$field");

    $this->assertDatabaseMissing('articles', [
        $article->getRouteKeyName() => $article->getRouteKey(),
    ]);
})
    ->with([
        'title', 'content',
    ]);

it('slug must be unique', function () {
    Article::factory()->create(['slug' => 'same-slug']);

    $data = jsonData(
        $article = Article::factory()->make(['slug' => 'same-slug'])
    );

    Passport::actingAs(userWithPermission('articles:store', $article->user));

    $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertUnprocessable() // 422
        ->assertSee('data\/attributes\/slug');

    $this->assertDatabaseCount('articles', 1);

});

it('rejects invalid slugs', function (string $slug, ?string $translationKey = null) {
    $data = jsonData(
        $article = Article::factory()->make(['slug' => $slug])
    );

    Passport::actingAs(userWithPermission('articles:store', $article->user));

    $response = $this->jsonApi()
        ->withData($data)
        ->post(route('api.v1.articles.store'))
        ->assertUnprocessable()
        ->assertSee('data\/attributes\/slug');

    if ($translationKey) {
        $response->assertSee(__($translationKey, ['attribute' => 'slug']));
    }

    $this->assertDatabaseMissing('articles', [
        $article->getRouteKeyName() => $article->getRouteKey(),
    ]);
})
    ->with([
        'empty' => ['', null],
        'invalid characters' => ['%$%#@', null],
        'contains underscores' => ['with_underscores', 'validation.no_underscores'],
        'starts with dash' => ['-start-with-dash', 'validation.no_starting_dashes'],
        'ends with dash' => ['end-with-dash-', 'validation.no_ending_dashes'],
    ]);

