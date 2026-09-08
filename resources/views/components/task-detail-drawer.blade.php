<div class="task-detail-backdrop" data-action="close-task" hidden="" id="task-detail-backdrop"></div>
<aside aria-labelledby="task-detail-title" aria-live="polite" aria-modal="true" class="task-detail-panel" hidden=""
    id="task-detail-panel" role="dialog">
    <header class="task-detail-panel__header">
        <div class="task-detail-heading">
            <p class="task-detail-panel__number"><span data-task-detail="number"></span><span>·</span><span
                    data-task-detail="group"></span></p>
            <div class="task-detail-chips"><span class="status-badge" data-task-detail="status"></span><span
                    class="priority-badge" data-task-detail="priority"></span><span class="confidence-badge"
                    data-task-detail="confidence"></span></div>
            <h2 data-task-detail="title" id="task-detail-title"></h2>
        </div>
        <button aria-label="Topshiriq tafsilotlarini yopish" class="icon-button task-detail-close"
            data-action="close-task" type="button">×</button>
    </header>
    <section aria-label="Topshiriq jarayoni" class="task-status-line">
        <div class="task-status-line__track"></div>
        <ol class="task-status-line__steps">

    <li data-status-step="created">
        <span class="task-status-line__dot">✓</span>
        <strong>Yaratildi</strong>
        <time data-status-time="created">—</time>
    </li>

    <li data-status-step="assigned">
        <span class="task-status-line__dot">•</span>
        <strong>Biriktirildi</strong>
        <time data-status-time="assigned">—</time>
    </li>

    <li data-status-step="accepted">
        <span class="task-status-line__dot">•</span>
        <strong>Qabul qilindi</strong>
        <time data-status-time="accepted">—</time>
    </li>

    <li data-status-step="in_progress">
        <span class="task-status-line__dot">•</span>
        <strong>Jarayonda</strong>
        <time data-status-time="in_progress">—</time>
    </li>

    <li data-status-step="awaiting_acceptance">
        <span class="task-status-line__dot">•</span>
        <strong>Qabul kutmoqda</strong>
        <time data-status-time="awaiting_acceptance">—</time>
    </li>

    <li data-status-step="completion_approved">
        <span class="task-status-line__dot">•</span>
        <strong>Tasdiqlandi</strong>
        <time data-status-time="completion_approved">—</time>
    </li>

    <li data-status-step="closed">
        <span class="task-status-line__dot">•</span>
        <strong>Yopildi</strong>
        <time data-status-time="closed">—</time>
    </li>

</ol>
    </section>
    <nav aria-label="Topshiriq tafsilotlari bo‘limlari" class="task-detail-tabs" role="tablist">
        <button aria-selected="true" class="task-detail-tab is-active" data-action="detail-tab"
            data-detail-tab="details" type="button">Tafsilotlar</button>
        <button aria-selected="false" class="task-detail-tab" data-action="detail-tab" data-detail-tab="chain"
            type="button">Zanjir</button>
        <button aria-selected="false" class="task-detail-tab" data-action="detail-tab" data-detail-tab="comments"
            type="button">Izohlar <span data-task-detail="comment-count">0</span></button>
        <button aria-selected="false" class="task-detail-tab" data-action="detail-tab" data-detail-tab="history"
            type="button">Tarix <span data-task-detail="history-count">0</span></button>
    </nav>
    <div class="task-detail-panel__body">
        <section class="task-detail-tab-panel is-active" data-detail-panel="details">
            <div class="task-detail-layout">
                <div class="task-detail-main-column">
                    <section class="task-detail-card task-source-card">
                        <header>
                            <h3>↗ Manba xabar</h3><span class="source-kind"
                                data-task-detail="source-kind"></span><button class="text-action" type="button">↗
                                Telegramda ochish</button>
                        </header>
                        <blockquote data-task-detail="source-text"></blockquote>
                        <footer class="source-meta"><span class="avatar avatar--sm"
                                data-task-detail="creator-initials"></span><span data-task-detail="creator"></span><time
                                data-task-detail="created-at"></time><span>Ajratish aniqligi <strong
                                    data-task-detail="confidence-value"></strong></span></footer>
                    </section>
                    <section class="task-detail-card task-detail-description-card">
                        <h3>Tavsif</h3>
                        <p data-task-detail="description"></p>
                    </section>
                    <section class="task-detail-card checklist-card">
                        <header>
                            <h3>Bajarilish punktlari</h3><strong><span data-task-detail="checklist-done">0</span>/<span
                                    data-task-detail="checklist-total">0</span></strong>
                        </header>
                        <div class="checklist-list" data-task-checklist=""></div>
                        <p class="checklist-empty" data-task-checklist-empty="" hidden="">Punktlar yo‘q</p>
                        <div class="checklist-add"><input aria-label="Yangi punkt" placeholder="Yangi punkt qo‘shish…"
                                type="text" /><button aria-label="Punkt qo‘shish" class="icon-button"
                                type="button">＋</button></div>
                    </section>
                </div>
                <aside class="task-detail-side-column">
                    <section class="deadline-card" data-deadline-card="">
                        <div class="deadline-card__icon">◷</div>
                        <div>
                            <p data-task-detail="deadline-label">Muddat belgilanmagan</p><strong
                                data-task-detail="deadline-value">SLA hisoblanmaydi</strong>
                        </div>
                        <button class="btn btn--ghost deadline-card__button" type="button">＋2 kun muddat
                            qo‘shish</button>
                    </section>
                    <dl class="task-detail-list task-detail-list--compact">
                        <div>
                            <dt>Holat</dt>
                            <dd><span class="status-badge" data-task-detail="status-side"></span></dd>
                        </div>
                        <div>
                            <dt>Ijrochi</dt>
                            <dd data-task-detail="assignee"></dd>
                        </div>
                        <div>
                            <dt>Ustuvorlik</dt>
                            <dd><span class="priority-badge" data-task-detail="priority-side"></span></dd>
                        </div>
                        <div>
                            <dt>Muddat</dt>
                            <dd><time data-task-detail="due-date"></time></dd>
                        </div>
                        <div>
                            <dt>Muallif</dt>
                            <dd><span class="detail-person"><span class="avatar avatar--sm"
                                        data-task-detail="creator-side-initials"></span><span
                                        data-task-detail="creator-side"></span></span></dd>
                        </div>
                        <div>
                            <dt>Yaratilgan</dt>
                            <dd><time data-task-detail="created-at-side"></time></dd>
                        </div>
                    </dl>
                    <div class="task-detail-actions">
                        <button class="btn btn--primary" data-action="start-task" type="button">Ishni
                            boshlash</button>
                        <button class="btn btn--ghost" data-action="cancel-assignment" type="button">Biriktirishni
                            bekor qilish</button>
                        <button class="btn btn--danger" data-action="archive-task" type="button">Topshiriqni
                            arxivlash</button>
                    </div>
                </aside>
            </div>
        </section>
        <section class="task-detail-tab-panel" data-detail-panel="chain" hidden="">
            <div class="task-detail-card task-chain-card">
                <h3>Zanjir</h3>
                <p class="muted" data-task-chain-summary="">Ushbu topshiriq mustaqil topshiriq.</p>
                <div class="task-chain-node"><span>◉</span><strong data-task-detail="title-chain"></strong><small
                        data-task-detail="number-chain"></small></div>
            </div>
        </section>
        <section class="task-detail-tab-panel" data-detail-panel="comments" hidden="">
            <div class="task-detail-card comments-card">
                <header>
                    <h3>Izohlar</h3><span class="count-pill" data-task-detail="comment-count-side">0</span>
                </header>
                <div class="comments-list" data-repeat="task.comments" data-task-comments=""></div>
                <p class="comments-empty" data-task-comments-empty="">Hozircha izohlar yo‘q.</p>
                <form class="comment-composer" data-action="comment-form"><textarea aria-label="Izoh"
                        placeholder="Izoh yozing…"></textarea><button class="btn btn--primary"
                        type="submit">Yuborish</button></form>
            </div>
        </section>
        <section class="task-detail-tab-panel" data-detail-panel="history" hidden="">
            <div class="task-detail-card history-card">
                <header>
                    <h3>Tarix va loglar</h3><span class="count-pill" data-task-detail="history-count-side">0</span>
                </header>
                <ol class="history-list" data-repeat="task.activity" data-task-history=""></ol>
            </div>
        </section>
    </div>
</aside>
