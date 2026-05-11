<?php

it('rechaza el acceso a productos sin autenticacion', function () {
    $response = $this->getJson('/api/products');

    $response->assertStatus(401);
});
