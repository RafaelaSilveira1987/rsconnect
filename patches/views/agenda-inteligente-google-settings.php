<!-- PATCH DE REFERÊNCIA PARA /agenda-inteligente -->
<section class="card smart-calendar-google-settings">
    <div class="section-heading">
        <div>
            <span class="eyebrow">Google Agenda</span>
            <h2>Modo de disponibilidade</h2>
        </div>
    </div>

    <div class="form-grid">
        <label class="field field-span-2">
            <span>Como a empresa controla os horários</span>
            <select name="availability_mode" data-calendar-mode>
                <option value="free_slots">Calcular pelos espaços livres da agenda</option>
                <option value="marked_events">Usar eventos marcados como VAGO</option>
            </select>
        </label>

        <label class="field">
            <span>ID do calendário Google</span>
            <input name="google_calendar_id" value="primary" autocomplete="off">
        </label>

        <label class="field">
            <span>Fuso horário</span>
            <input name="google_timezone" value="America/Sao_Paulo" autocomplete="off">
        </label>

        <div data-mode-panel="free_slots" class="field-span-2">
            <label class="field">
                <span>Webhook n8n — espaços livres</span>
                <input type="url" name="free_slots_webhook_url" placeholder="https://n8n.../webhook/rsconnect-agenda-google-espacos-livres">
            </label>
            <label class="check-row">
                <input type="checkbox" name="ignore_transparent_events" value="1" checked>
                <span>Ignorar eventos configurados como Disponível (transparent)</span>
            </label>
        </div>

        <div data-mode-panel="marked_events" class="field-span-2" hidden>
            <label class="field">
                <span>Webhook n8n — eventos VAGO</span>
                <input type="url" name="marked_events_webhook_url" placeholder="https://n8n.../webhook/rsconnect-agenda-google-eventos-vago">
            </label>
            <div class="form-grid">
                <label class="field">
                    <span>Título online</span>
                    <input name="marked_online_title" value="VAGO — ONLINE">
                </label>
                <label class="field">
                    <span>Título presencial</span>
                    <input name="marked_in_person_title" value="VAGO — PRESENCIAL">
                </label>
                <label class="field">
                    <span>Prefixo da pré-reserva</span>
                    <input name="marked_hold_prefix" value="PRÉ-RESERVADO">
                </label>
                <label class="field">
                    <span>Prefixo da confirmação</span>
                    <input name="marked_confirmed_prefix" value="AGENDADO">
                </label>
            </div>
            <label class="check-row">
                <input type="checkbox" name="marked_require_transparent" value="1" checked>
                <span>Exigir que eventos VAGO estejam como Disponível (transparent)</span>
            </label>
        </div>
    </div>
</section>

<script>
(() => {
    const select = document.querySelector('[data-calendar-mode]');
    if (!select) return;

    const refresh = () => {
        document.querySelectorAll('[data-mode-panel]').forEach((panel) => {
            panel.hidden = panel.dataset.modePanel !== select.value;
        });
    };

    select.addEventListener('change', refresh);
    refresh();
})();
</script>
