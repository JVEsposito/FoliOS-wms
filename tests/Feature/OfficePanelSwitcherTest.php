<?php

namespace Tests\Feature;

use Tests\TestCase;

class OfficePanelSwitcherTest extends TestCase
{
    public function test_independent_office_sections_render_as_selectable_panels(): void
    {
        $offices = [
            '/oficina/gerencia' => [
                'group' => 'management',
                'panels' => ['cameras', 'products', 'materials', 'precooling', 'weighbridge', 'alerts'],
            ],
            '/oficina/accesos' => [
                'group' => 'administration',
                'panels' => ['seasons', 'clients', 'labels', 'profiles', 'users', 'devices'],
            ],
            '/oficina/materiales/catalogos' => [
                'group' => 'materials-catalog',
                'panels' => ['season', 'clients', 'providers', 'items', 'destinations'],
            ],
            '/oficina/administracion/maestros-temporada' => [
                'group' => 'validation-catalog',
                'panels' => ['clients', 'brands', 'categories', 'species', 'varieties', 'calibers', 'packages', 'csg', 'imports'],
            ],
            '/oficina/envases/cuenta-corriente' => [
                'group' => 'container-accounts',
                'panels' => ['balances', 'stock', 'pending', 'reservations', 'movements'],
            ],
        ];

        foreach ($offices as $route => $configuration) {
            $response = $this->get($route);

            $response
                ->assertOk()
                ->assertSee('data-office-panel-switcher="'.$configuration['group'].'"', false)
                ->assertSee('role="tablist"', false)
                ->assertSee('role="tabpanel"', false);

            foreach ($configuration['panels'] as $panel) {
                $response->assertSee(
                    'data-office-panel-id="'.$panel.'"',
                    false,
                );
            }
        }
    }

    public function test_sequential_workflows_remain_visible_without_a_panel_switcher(): void
    {
        foreach ([
            '/oficina/frigorifico/despacho/cargas',
            '/oficina/romana',
            '/oficina/materiales/recepcion',
        ] as $route) {
            $this->get($route)
                ->assertOk()
                ->assertDontSee('data-office-panel-switcher=', false);
        }
    }

    public function test_materials_switcher_is_limited_to_the_catalog_route(): void
    {
        $this->get('/oficina/materiales')
            ->assertOk()
            ->assertDontSee('data-office-panel-switcher="materials-catalog"', false);

        $this->get('/oficina/materiales/catalogos')
            ->assertOk()
            ->assertSee('data-office-panel-switcher="materials-catalog"', false);
    }
}
