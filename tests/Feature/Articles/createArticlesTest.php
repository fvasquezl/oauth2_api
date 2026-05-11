<?php

use App\Models\Article;

it('guest users cannot create articles', function () {

    $data = Article::factory()->raw();

    $this->postJson(route('api.v1.articles.store'), $data)
        ->assertUnauthorized(); // 401

    $this->assertDatabaseEmpty('articles');

});
