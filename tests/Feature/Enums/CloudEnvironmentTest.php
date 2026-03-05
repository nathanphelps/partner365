<?php

use App\Enums\CloudEnvironment;

test('commercial environment returns correct endpoints', function () {
    $env = CloudEnvironment::Commercial;

    expect($env->loginUrl())->toBe('login.microsoftonline.com');
    expect($env->graphBaseUrl())->toBe('https://graph.microsoft.com/v1.0');
    expect($env->defaultScopes())->toBe('https://graph.microsoft.com/.default');
});

test('gcc high environment returns correct endpoints', function () {
    $env = CloudEnvironment::GccHigh;

    expect($env->loginUrl())->toBe('login.microsoftonline.us');
    expect($env->graphBaseUrl())->toBe('https://graph.microsoft.us/v1.0');
    expect($env->defaultScopes())->toBe('https://graph.microsoft.us/.default');
});

test('cloud environment can be created from string value', function () {
    expect(CloudEnvironment::from('commercial'))->toBe(CloudEnvironment::Commercial);
    expect(CloudEnvironment::from('gcc_high'))->toBe(CloudEnvironment::GccHigh);
});

test('cloud environment label returns display name', function () {
    expect(CloudEnvironment::Commercial->label())->toBe('Commercial');
    expect(CloudEnvironment::GccHigh->label())->toBe('GCC High');
});
