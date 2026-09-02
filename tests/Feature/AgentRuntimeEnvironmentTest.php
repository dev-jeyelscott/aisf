<?php

use App\Services\AgentRuntimeEnvironment;

it('falls back to the ambient process environment when no runtime PATH/HOME override is configured', function () {
    config()->set('aisf.agent_runtime_path', null);
    config()->set('aisf.agent_runtime_home', null);

    expect(app(AgentRuntimeEnvironment::class)->resolve())->toBe([]);
});

it('overrides only PATH and HOME when configured, leaving the rest of the ambient environment untouched', function () {
    config()->set('aisf.agent_runtime_path', '/resolved/bin:/usr/bin');
    config()->set('aisf.agent_runtime_home', '/resolved/home');

    expect(app(AgentRuntimeEnvironment::class)->resolve())->toBe([
        'PATH' => '/resolved/bin:/usr/bin',
        'HOME' => '/resolved/home',
    ]);
});

it('overrides PATH independently of HOME', function () {
    config()->set('aisf.agent_runtime_path', '/resolved/bin');
    config()->set('aisf.agent_runtime_home', null);

    expect(app(AgentRuntimeEnvironment::class)->resolve())->toBe([
        'PATH' => '/resolved/bin',
    ]);
});
