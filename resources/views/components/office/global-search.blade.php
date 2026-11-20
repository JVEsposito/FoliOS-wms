@props(['inContext' => false])

<form class="estiba-office-search" action="/oficina/consultas/busqueda" method="get" role="search"
    data-navigation-permissions="puede_consultar_oficina_consultas"
    data-navigation-module="consultas.busqueda">
    <label @class(['estiba-office-nav-label', 'estiba-office-sr' => $inContext]) for="officeGlobalSearch">Buscar folio, lote o guía</label>
    <div>
        <input id="officeGlobalSearch" name="q" type="search" minlength="2" maxlength="100" placeholder="Folio, lote, REC, CSG…" autocomplete="off" required>
        <button type="submit" aria-label="Buscar"><x-estiba.icon name="search" /></button>
    </div>
</form>
