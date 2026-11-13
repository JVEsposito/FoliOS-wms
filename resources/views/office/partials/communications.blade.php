<dialog id="communicationsDialog" class="comms-dialog" aria-labelledby="commsTitle">
    <header class="comms-header"><div><h2 id="commsTitle">Comunicaciones</h2><small>Equipo FoliOS · Chat y correo interno</small></div><button type="button" data-comms-close>Cerrar</button></header>
    <nav class="comms-tabs" aria-label="Tipo de comunicación"><button type="button" data-comms-tab="chat" aria-pressed="true">Chat</button><button type="button" data-comms-tab="mail" aria-pressed="false">Correo</button><button type="button" data-comms-refresh>Actualizar</button></nav>
    <p class="comms-status" data-comms-status role="status" aria-live="polite"></p>
    <section class="comms-workspace" data-comms-chat>
        <aside class="comms-rail" aria-label="Conversaciones"><button type="button" data-private-new>Nuevo privado</button>
            <section data-private-picker hidden><form data-private-search><label>Buscar persona<input name="buscar" type="search" maxlength="100" autocomplete="off"></label><button type="submit">Buscar</button></form><div data-private-users></div><button type="button" data-private-more hidden>Más personas</button><button type="button" data-private-cancel>Volver</button></section>
            <div data-conversation-list></div><button type="button" data-conversations-more hidden>Más conversaciones</button>
        </aside>
        <section class="comms-chat-main" aria-label="Chat seleccionado"><header class="comms-conversation-heading"><h3 data-conversation-title>Chat global</h3><button type="button" data-chat-older hidden>Mensajes anteriores</button></header>
            <div class="comms-messages" data-chat-messages role="log" aria-label="Mensajes" aria-live="polite"></div>
            <button type="button" data-chat-bottom hidden>Hay mensajes nuevos · Ir al final</button>
            <section class="comms-stickers" data-sticker-panel hidden><header><h3>Stickers compartidos</h3><button type="button" data-stickers-close>Cerrar stickers</button></header>
                <form data-sticker-search><label>Buscar sticker<input name="buscar" type="search" maxlength="80"></label><button type="submit">Buscar</button></form>
                <div class="comms-sticker-grid" data-sticker-grid></div><button type="button" data-stickers-more hidden>Más stickers</button>
                <details><summary>Cargar un sticker</summary><p>Se comparte con el equipo. PNG, WebP o GIF, hasta 2 MB y 1024 × 1024 píxeles. Conserva transparencia y animación.</p><form data-sticker-upload><label>Nombre<input name="nombre" maxlength="80" required></label><label>Archivo<input name="archivo" type="file" accept="image/png,image/webp,image/gif" required></label><button type="submit">Subir sticker</button></form></details>
            </section>
            <form class="comms-chat-compose" data-chat-form><div data-chat-sticker hidden></div><label for="commsChatText">Mensaje<textarea id="commsChatText" name="texto" rows="2" maxlength="4000" placeholder="Escribe un mensaje…"></textarea></label><div><button type="button" data-stickers-open>Stickers</button><button type="submit" data-chat-send>Enviar mensaje</button></div></form>
        </section>
    </section>
    <section class="comms-workspace" data-comms-mail hidden>
        <aside class="comms-rail" aria-label="Buzón"><button type="button" data-mail-new>Redactar</button><nav class="comms-folders" aria-label="Bandejas"><button type="button" data-mail-folder="recibidos" aria-pressed="true">Recibidos</button><button type="button" data-mail-folder="enviados" aria-pressed="false">Enviados</button><button type="button" data-mail-folder="archivados" aria-pressed="false">Archivados</button></nav>
            <form data-mail-search><label>Buscar correo<input name="buscar" type="search" maxlength="100"></label><button type="submit">Buscar</button></form><div data-mail-list></div><div class="comms-pagination"><button type="button" data-mail-prev>Anterior</button><span data-mail-page></span><button type="button" data-mail-next>Siguiente</button></div>
        </aside>
        <section class="comms-mail-main" aria-label="Contenido del correo"><div data-mail-detail><p>Selecciona un correo para leerlo.</p></div>
            <form data-mail-compose hidden><h3 data-mail-compose-title>Nuevo correo</h3><div class="comms-recipient-chips" data-mail-recipients></div><label>Buscar destinatarios<input data-recipient-search type="search" maxlength="100" autocomplete="off"></label><div class="comms-recipient-options" data-recipient-options></div><button type="button" data-recipients-more hidden>Más destinatarios</button><label>Asunto<input name="asunto" maxlength="160" required></label><label>Mensaje<textarea name="texto" maxlength="10000" rows="8" required></textarea></label><div class="comms-compose-actions"><button type="submit" data-mail-send>Enviar correo</button><button type="button" data-mail-cancel>Cancelar borrador</button></div></form>
        </section>
    </section>
</dialog>
