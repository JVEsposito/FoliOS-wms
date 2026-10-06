<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light dark"><title>FoliOS · {{ $titulo }}</title>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
@vite(['resources/css/office.css', 'resources/css/office-packed-fruit.css', $script])
@endif
</head><body>
<section class="office-access" id="officeAccess"><div class="office-access__brand"><p class="eyebrow">FoliOS · FRUTA EMBALADA</p><h1>{{ $titulo }}</h1><p>Captura de fruta proveniente de otras plantas.</p></div>
<form class="office-access__form" id="officeLoginForm"><h2>Acceso de Oficina</h2><label>Correo electrónico<input name="email" type="email" autocomplete="username" required></label><label>Contraseña<input name="password" type="password" autocomplete="current-password" required></label><p class="form-error" id="officeLoginError" role="alert"></p><button class="primary-button">Entrar</button></form></section>
<main class="office-app is-hidden" id="officeApp">
<x-office.navigation :domain="$dominio" :office="$oficina" context="FRUTA EMBALADA" icon="↘" />
<section class="rfe-workspace"><header class="rfe-heading"><div><p class="eyebrow">{{ $dominio === 'administracion' ? 'ADMINISTRACIÓN' : 'FRÍO · RECEPCIONES EXTERNAS' }}</p><h1>{{ $titulo }}</h1></div><button class="secondary-button" id="reloadButton" type="button">Actualizar</button></header>
<p id="rfeMessage" role="status" aria-live="polite"></p>
@yield('workspace')
</section></main>
@if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
@vite('resources/js/office-navigation.js')
@endif
</body></html>