<?php

namespace App\View\Components\Office;

use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Navigation extends Component
{
    public function __construct(
        public string $domain,
        public string $office,
        public string $context = 'OPERACIÓN DE OFICINA',
        public string $icon = '◆',
    ) {}

    public function render(): View|Closure|string
    {
        return view('components.office.navigation');
    }
}
