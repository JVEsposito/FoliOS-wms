<dialog id="communicationsDialog" class="comms-dialog" aria-labelledby="commsTitle">
    <header class="comms-header"><div><h2 id="commsTitle">Comunicaciones</h2><small>Equipo FoliOS · Chat y correo interno</small></div><button type="button" data-comms-close>Cerrar</button></header>
    <nav class="comms-tabs" aria-label="Tipo de comunicación"><button type="button" data-comms-tab="chat" aria-pressed="true">Chat</button><button type="button" data-comms-tab="mail" aria-pressed="false">Correo</button><button type="button" data-comms-refresh>Actualizar</button></nav>
    <p class="comms-status" data-comms-status role="status" aria-live="polite"></p>
    <section class="comms-workspace" data-comms-chat>
        <aside class="comms-rail" aria-label="Conversaciones"><button type="button" data-private-new>Nuevo privado</button><button type="button" data-group-new>Nuevo grupo</button>
            <section data-private-picker hidden><h3 data-directory-title>Nuevo privado</h3><form data-private-search><label>Buscar persona<input name="buscar" type="search" maxlength="100" autocomplete="off"></label><button type="submit">Buscar</button></form><div data-private-users></div><button type="button" data-private-more hidden>Más personas</button><button type="button" data-private-cancel>Volver</button></section>
            <section data-group-picker hidden>
                <h3 data-group-title>Nuevo grupo</h3>
                <form data-group-form>
                    <label>Nombre del grupo<input name="nombre" maxlength="100" required></label>
                    <div class="comms-recipient-chips" data-group-members></div>
                    <div data-group-directory>
                        <label>Buscar integrantes<input name="buscar" type="search" maxlength="100" autocomplete="off"></label>
                        <div class="comms-recipient-options" data-group-options></div>
                        <button type="button" data-group-more hidden>Más personas</button>
                    </div>
                    <div class="comms-compose-actions">
                        <button type="submit" data-group-save>Crear grupo</button>
                        <button type="button" data-group-close>Volver</button>
                    </div>
                </form>
            </section>
            <div data-conversation-list></div><button type="button" data-conversations-more hidden>Más conversaciones</button>
        </aside>
        <section class="comms-chat-main" aria-label="Chat seleccionado"><header class="comms-conversation-heading"><h3 data-conversation-title>Chat global</h3><button type="button" data-group-open hidden>Integrantes</button><button type="button" data-global-moderation hidden>Silenciar usuario</button><button type="button" data-chat-older hidden>Mensajes anteriores</button></header>
            <div class="comms-messages" data-chat-messages role="log" aria-label="Mensajes" aria-live="polite"></div>
            <button type="button" data-chat-bottom hidden>Hay mensajes nuevos · Ir al final</button>
            <section class="comms-stickers" data-sticker-panel hidden><header><h3>Stickers compartidos</h3><button type="button" data-stickers-close>Cerrar stickers</button></header>
                <form data-sticker-search><label>Buscar sticker<input name="buscar" type="search" maxlength="80"></label><button type="submit">Buscar</button></form>
                <div class="comms-sticker-grid" data-sticker-grid></div><button type="button" data-stickers-more hidden>Más stickers</button>
                <details><summary>Cargar un sticker</summary><p>Se comparte con el equipo. PNG, WebP o GIF, hasta 2 MB y 1024 × 1024 píxeles. Conserva transparencia y animación.</p><form data-sticker-upload><label>Nombre<input name="nombre" maxlength="80" required></label><label>Archivo<input name="archivo" type="file" accept="image/png,image/webp,image/gif" required></label><button type="submit">Subir sticker</button></form></details>
            </section>
            <form class="comms-chat-compose" data-chat-form><p data-chat-muted role="status" hidden></p><div data-chat-sticker hidden></div><label for="commsChatText">Mensaje<textarea id="commsChatText" name="texto" rows="2" maxlength="3000" placeholder="Escribe un mensaje… # cita un folio, recepción o carga; @ menciona a una persona" aria-describedby="commsChatCiteHelp" aria-autocomplete="list" aria-controls="commsChatSuggest"></textarea></label><div class="comms-suggest" id="commsChatSuggest" data-cite-suggest role="listbox" aria-label="Citas sugeridas" hidden></div><small id="commsChatCiteHelp" class="comms-cite-help">Con # citas operaciones (folios, recepciones, cargas, prefrío, lotes…) y con @ mencionas a alguien del equipo.</small><div><span class="comms-cite-actions"><button type="button" data-stickers-open>Stickers</button><button type="button" data-cite-insert="#" aria-label="Citar una operación" title="Citar folio, recepción, carga…"># Citar</button><button type="button" data-cite-insert="@" aria-label="Mencionar a una persona" title="Mencionar a alguien del equipo">@ Mencionar</button></span><button type="submit" data-chat-send>Enviar mensaje</button></div></form>
        </section>
    </section>
    <section class="comms-workspace" data-comms-mail hidden>
        <aside class="comms-rail" aria-label="Buzón"><button type="button" data-mail-new>Redactar</button><nav class="comms-folders" aria-label="Bandejas"><button type="button" data-mail-folder="recibidos" aria-pressed="true">Recibidos</button><button type="button" data-mail-folder="enviados" aria-pressed="false">Enviados</button><button type="button" data-mail-folder="archivados" aria-pressed="false">Archivados</button></nav>
            <form data-mail-search><label>Buscar correo<input name="buscar" type="search" maxlength="100"></label><button type="submit">Buscar</button></form><div data-mail-list></div><div class="comms-pagination"><button type="button" data-mail-prev>Anterior</button><span data-mail-page></span><button type="button" data-mail-next>Siguiente</button></div>
        </aside>
        <section class="comms-mail-main" aria-label="Contenido del correo"><div data-mail-detail><p>Selecciona un correo para leerlo.</p></div>
            <form data-mail-compose hidden><h3 data-mail-compose-title>Nuevo correo</h3><div class="comms-recipient-chips" data-mail-recipients></div><label>Buscar destinatarios<input data-recipient-search type="search" maxlength="100" autocomplete="off"></label><div class="comms-recipient-options" data-recipient-options></div><button type="button" data-recipients-more hidden>Más destinatarios</button><label>Asunto<input name="asunto" maxlength="160" required></label><label>Mensaje<textarea name="texto" maxlength="8000" rows="8" required placeholder="# cita una operación; @ menciona a una persona" aria-autocomplete="list" aria-controls="commsMailSuggest"></textarea></label><div class="comms-suggest" id="commsMailSuggest" data-cite-suggest role="listbox" aria-label="Citas sugeridas" hidden></div><div class="comms-cite-actions"><button type="button" data-cite-insert="#" aria-label="Citar una operación en el correo"># Citar</button><button type="button" data-cite-insert="@" aria-label="Mencionar a una persona en el correo">@ Mencionar</button></div><div class="comms-compose-actions"><button type="submit" data-mail-send>Enviar correo</button><button type="button" data-mail-cancel>Cancelar borrador</button></div></form>
        </section>
    </section>
    <section class="comms-cite-card" data-chat-moderation role="region" aria-labelledby="commsModerationTitle" hidden>
        <header><h3 id="commsModerationTitle" data-moderation-title></h3><button type="button" data-moderation-close>Cerrar</button></header>
        <form data-moderation-form>
            <p data-moderation-description></p>
            <label data-moderation-duration>Duración en minutos<input name="minutos" type="number" min="1" max="10080" value="60" required></label>
            <div class="comms-compose-actions"><button type="submit" data-moderation-submit></button><button type="button" data-moderation-revoke hidden>Levantar silencio</button></div>
        </form>
    </section>
    <section class="comms-cite-card" data-cite-card role="region" aria-live="polite" aria-labelledby="commsCiteCardTitle" hidden><header><div><small data-cite-card-type></small><h3 id="commsCiteCardTitle" data-cite-card-title></h3></div><button type="button" data-cite-card-close>Cerrar ficha</button></header><div data-cite-card-body></div></section>
</dialog>
