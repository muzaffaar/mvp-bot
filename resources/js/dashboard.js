(() => {
    "use strict";

    const state = {
        tasks: [],
        selectedTaskId: null,
        currentView: "kanban",
        quickFilter: "all",
        tablePage: 1,
        tablePageSize: 10,
        peoplePage: 1,
        peoplePageSize: 8,
        filters: {
            status: "",
            priority: "",
            assignee: "",
            creator: "",
            dueDate: "",
            dueDateFrom: "",
            dueDateTo: "",
            search: ""
        }
    };

    const page = document.body.dataset.page;
    const db = window.INITIAL_DB || {};

    function getPerson(id) {
        return (db.persons || []).find((person) => String(person.id) === String(id)) || null;
    }

    function personName(id) {
        if (!id) return "Unassigned";
        const person = getPerson(id);
        return person ? person.fullName : "Unknown";
    }

    function creatorId(task) {
        return task.creatorId || task.createdBy || task.createdById || null;
    }

    function formatDate(value) {
        if (!value) return "No due date";
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return "No due date";
        return new Intl.DateTimeFormat("en", { dateStyle: "medium" }).format(date);
    }

    function normalizeStatus(status) {
        const labels = {
            NEW: "New",
            ASSIGNED: "Assigned",
            IN_PROGRESS: "In progress",
            PAUSED: "Paused",
            SUBMITTED: "Review",
            REVIEW: "Review",
            ACCEPTED: "Done",
            CLOSED: "Done"
        };
        return labels[status] || status || "Other";
    }

    function normalizePriority(priority) {
        return priority ? priority.charAt(0).toUpperCase() + priority.slice(1).toLowerCase() : "Normal";
    }

    function setText(root, selector, value) {
        const element = root.querySelector(selector);
        if (element) element.textContent = value || "—";
    }

    function setData(root, selector, key, value) {
        const element = root.querySelector(selector);
        if (element) element.dataset[key] = value || "";
    }

    function cloneTemplate(id) {
        const template = document.getElementById(id);
        return template ? template.content.cloneNode(true) : null;
    }

    function clearNode(node) {
        if (node) node.replaceChildren();
    }

    function selectedValues(select) {
        return Array.from(select.selectedOptions).map((option) => option.value).filter(Boolean);
    }

    function statusBucket(status) {
        if (status === "NEW") return "NEW";
        if (status === "ASSIGNED") return "ASSIGNED";
        if (["IN_PROGRESS", "PAUSED"].includes(status)) return "IN_PROGRESS";
        if (["SUBMITTED", "REVIEW"].includes(status)) return "SUBMITTED";
        if (["ACCEPTED", "CLOSED"].includes(status)) return "ACCEPTED";
        return "NEW";
    }

    function taskLabels(task) {
        const labels = task.labels || task.tags || [];
        if (Array.isArray(labels)) return labels;
        return [];
    }

    function isCompleted(task) {
        return ["ACCEPTED", "CLOSED"].includes(task.status);
    }

    function isOverdue(task, referenceDate = new Date()) {
        return Boolean(task.deadline) && new Date(task.deadline) < referenceDate && !isCompleted(task);
    }

    function getFilteredTasks() {
        const now = new Date();
        const today = new Date(now.getFullYear(), now.getMonth(), now.getDate());
        const tomorrow = new Date(today);
        tomorrow.setDate(today.getDate() + 1);
        const tomorrowEnd = new Date(tomorrow);
        tomorrowEnd.setDate(tomorrow.getDate() + 1);
        const thisWeekEnd = new Date(today);
        thisWeekEnd.setDate(today.getDate() + (7 - today.getDay()));
        thisWeekEnd.setHours(23, 59, 59, 999);
        const nextWeekStart = new Date(thisWeekEnd);
        nextWeekStart.setDate(thisWeekEnd.getDate() + 1);
        nextWeekStart.setHours(0, 0, 0, 0);
        const nextWeekEnd = new Date(nextWeekStart);
        nextWeekEnd.setDate(nextWeekStart.getDate() + 7);
        const query = state.filters.search.trim().toLowerCase();

        return state.tasks.filter((task) => {
            const haystack = [
                task.title,
                task.description,
                task.number,
                personName(task.assigneeId),
                personName(creatorId(task)),
                ...taskLabels(task)
            ].filter(Boolean).join(" ").toLowerCase();

            if (query && !haystack.includes(query)) return false;
            if (state.filters.status && task.status !== state.filters.status) return false;
            if (state.filters.priority && task.priority !== state.filters.priority) return false;
            if (state.filters.assignee && String(task.assigneeId || "unassigned") !== state.filters.assignee) return false;
            if (state.filters.creator && String(creatorId(task) || "unknown") !== state.filters.creator) return false;

            const due = task.deadline ? new Date(task.deadline) : null;
            if (state.filters.dueDate) {
                const key = state.filters.dueDate;
                if (key === "none") return !due;
                if (!due) return false;
                if (key === "overdue" && !isOverdue(task, today)) return false;
                if (key === "today" && !(due >= today && due < tomorrow)) return false;
                if (key === "tomorrow" && !(due >= tomorrow && due < tomorrowEnd)) return false;
                if (key === "this-week" && !(due >= today && due <= thisWeekEnd)) return false;
                if (key === "next-week" && !(due >= nextWeekStart && due < nextWeekEnd)) return false;
            }
            if (state.filters.dueDateFrom || state.filters.dueDateTo) {
                if (!due) return false;
                const dueDay = new Date(due.getFullYear(), due.getMonth(), due.getDate());
                if (state.filters.dueDateFrom) {
                    const from = new Date(`${state.filters.dueDateFrom}T00:00:00`);
                    if (dueDay < from) return false;
                }
                if (state.filters.dueDateTo) {
                    const to = new Date(`${state.filters.dueDateTo}T00:00:00`);
                    if (dueDay > to) return false;
                }
            }
            return true;
        });
    }

    function populateSelect(select, values) {
        if (!select) return;
        clearNode(select);
        const placeholder = select.dataset.placeholder;
        if (placeholder) {
            const fragment = cloneTemplate("filter-option-template");
            const option = fragment?.querySelector("option");
            if (option) {
                option.value = "";
                option.textContent = placeholder;
                select.append(fragment);
            }
        }
        values.forEach((value) => {
            const fragment = cloneTemplate("filter-option-template");
            const option = fragment?.querySelector("option");
            if (!option) return;
            option.value = String(value.value);
            option.textContent = value.label;
            select.append(fragment);
        });
    }

    function renderLabels(card, task) {
        const container = card.querySelector("[data-task-labels]");
        if (!container) return;
        clearNode(container);
        taskLabels(task).slice(0, 3).forEach((label) => {
            const fragment = cloneTemplate("task-label-template");
            const element = fragment?.querySelector("[data-task-label]");
            if (!element) return;
            element.textContent = typeof label === "string" ? label : label.name || label.title || "Label";
            container.append(fragment);
        });
    }

    function formatDateTime(value) {
        if (!value) return "—";
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return "—";
        return new Intl.DateTimeFormat("uz-UZ", {
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit"
        }).format(date);
    }

    function initialsFromName(name) {
        return String(name || "?")
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part.charAt(0))
            .join("")
            .toUpperCase();
    }

    function deadlineState(task) {
        if (!task.deadline) return "none";
        if (isCompleted(task)) return "complete";
        const now = new Date();
        const due = new Date(task.deadline);
        if (Number.isNaN(due.getTime())) return "none";
        const hours = (due.getTime() - now.getTime()) / 3600000;
        if (hours < 0) return "overdue";
        if (hours <= 24) return "urgent";
        if (hours <= 72) return "warning";
        return "safe";
    }

    function deadlineText(task) {
        const stateKey = deadlineState(task);
        if (stateKey === "none") return "Muddat belgilanmagan — SLA hisoblanmaydi";
        if (stateKey === "complete") return "Topshiriq yakunlangan";
        if (stateKey === "overdue") return "Muddat o‘tgan";
        if (stateKey === "urgent") return "Muddat juda yaqin";
        if (stateKey === "warning") return "Muddat xavfi ostida";
        return "Muddat belgilangan";
    }

    function taskProgress(task) {
        const list = Array.isArray(task.checklist) ? task.checklist : [];
        if (!list.length) return null;
        const done = list.filter((item) => item.completed || item.done || item.isCompleted).length;
        return { done, total: list.length, percent: Math.round((done / list.length) * 100) };
    }

    function renderTaskCard(container, task) {
        const fragment = cloneTemplate("task-card-template");
        const card = fragment?.querySelector("[data-task-id]");
        if (!card) return;
        card.dataset.taskId = task.id;
        card.dataset.priority = task.priority || "NORMAL";
        setText(card, '[data-task-field="number"]', task.number || task.id);
        setText(card, '[data-task-field="title"]', task.title);
        setText(card, '[data-task-field="assignee"]', personName(task.assigneeId));
        setText(card, '[data-task-field="assignee-initials"]', initialsFromName(personName(task.assigneeId)));
        setText(card, '[data-task-field="due-date"]', formatDate(task.deadline));
        const due = card.querySelector('[data-task-field="due-date"]');
        if (due) due.dataset.deadlineState = deadlineState(task);
        const progress = taskProgress(task);
        const progressWrap = card.querySelector("[data-task-progress-wrap]");
        if (progress && progressWrap) {
            progressWrap.hidden = false;
            const bar = card.querySelector("[data-task-progress-bar]");
            if (bar) bar.style.width = `${progress.percent}%`;
            setText(card, "[data-task-progress]", `${progress.done}/${progress.total}`);
        }
        renderLabels(card, task);
        container.append(fragment);
    }

    function renderKanban(tasks) {
        const buckets = ["NEW", "ASSIGNED", "IN_PROGRESS", "SUBMITTED", "ACCEPTED"];
        document.querySelectorAll("[data-kanban-tasks]").forEach(clearNode);
        buckets.forEach((bucket) => {
            const columnTasks = tasks.filter((task) => statusBucket(task.status) === bucket);
            const container = document.querySelector(`[data-kanban-tasks="${bucket}"]`);
            const count = document.querySelector(`[data-column-count="${bucket}"]`);
            const empty = document.querySelector(`[data-column-empty="${bucket}"]`);
            if (count) count.textContent = String(columnTasks.length);
            if (empty) empty.hidden = columnTasks.length > 0;
            if (container) columnTasks.forEach((task) => renderTaskCard(container, task));
        });
    }

    function renderTable(tasks) {
        const body = document.getElementById("tasks-table-body");
        if (!body) return;
        const totalPages = Math.max(1, Math.ceil(tasks.length / state.tablePageSize));
        state.tablePage = Math.min(Math.max(1, state.tablePage), totalPages);
        const start = (state.tablePage - 1) * state.tablePageSize;
        const pageTasks = tasks.slice(start, start + state.tablePageSize);
        clearNode(body);
        pageTasks.forEach((task) => {
            const fragment = cloneTemplate("task-row-template");
            const row = fragment?.querySelector("[data-task-id]");
            if (!row) return;
            row.dataset.taskId = task.id;
            setText(row, '[data-task-field="title"]', task.title);
            setText(row, '[data-task-field="number"]', task.number || task.id);
            setText(row, '[data-task-field="status"]', normalizeStatus(task.status));
            setData(row, '[data-task-field="status"]', "status", task.status || "");
            setText(row, '[data-task-field="priority"]', normalizePriority(task.priority));
            setData(row, '[data-task-field="priority"]', "priority", task.priority || "NORMAL");
            setText(row, '[data-task-field="assignee"]', personName(task.assigneeId));
            setText(row, '[data-task-field="assignee-initials"]', initialsFromName(personName(task.assigneeId)));
            setText(row, '[data-task-field="creator"]', personName(creatorId(task)));
            setText(row, '[data-task-field="due-date"]', formatDate(task.deadline));
            setText(row, '[data-task-field="created-at"]', formatDate(task.createdAt));
            body.append(fragment);
        });
        renderTablePagination(tasks.length, totalPages, start, pageTasks.length);
    }

    function renderTablePagination(total, totalPages, start, count) {
        const summary = document.querySelector("[data-table-summary]");
        const pages = document.querySelector("[data-table-pages]");
        const previous = document.querySelector('[data-action="table-prev"]');
        const next = document.querySelector('[data-action="table-next"]');
        if (summary) summary.textContent = total ? `${start + 1}–${start + count} / ${total}` : "0 / 0";
        if (previous) previous.disabled = state.tablePage <= 1;
        if (next) next.disabled = state.tablePage >= totalPages;
        if (!pages) return;
        clearNode(pages);
        const visible = Array.from({ length: totalPages }, (_, index) => index + 1).filter((pageNumber) => {
            return totalPages <= 5 || pageNumber === 1 || pageNumber === totalPages || Math.abs(pageNumber - state.tablePage) <= 1;
        });
        visible.forEach((pageNumber) => {
            const fragment = cloneTemplate("pagination-page-template");
            const button = fragment?.querySelector('[data-action="table-page"]');
            if (!button) return;
            button.dataset.page = String(pageNumber);
            button.textContent = String(pageNumber);
            button.classList.toggle("is-active", pageNumber === state.tablePage);
            button.setAttribute("aria-current", pageNumber === state.tablePage ? "page" : "false");
            pages.append(fragment);
        });
    }

    function getTaskActivity(task) {
        const activity = [
            { title: "Topshiriq yaratildi", description: "Tizimga yangi topshiriq kiritildi.", time: task.createdAt },
            { title: "Ijrochiga biriktirildi", description: `${personName(task.assigneeId)} ijrochi sifatida belgilandi.`, time: task.assigneeId ? task.startedAt || task.createdAt : null },
            { title: "Ish boshlandi", description: "Ijro jarayoni boshlandi.", time: task.startedAt },
            { title: "Qabulga yuborildi", description: "Natija qabul qilish uchun yuborildi.", time: task.submittedAt },
            { title: "Qabul qilindi", description: "Topshiriq natijasi qabul qilindi.", time: task.acceptedAt },
            { title: "Topshiriq yopildi", description: "Topshiriq yakuniy holatda yopildi.", time: task.closedAt }
        ].filter((item) => item.time);
        return activity;
    }

    function updateStatusLine(panel, task) {
        const stageMap = {
            NEW: 0,
            ASSIGNED: 1,
            IN_PROGRESS: 2,
            PAUSED: 2,
            SUBMITTED: 3,
            REVIEW: 3,
            ACCEPTED: 4,
            CLOSED: 5
        };
        const currentStage = stageMap[task.status] ?? 0;
        panel.dataset.statusStage = String(currentStage);
        panel.querySelectorAll("[data-status-step]").forEach((step, index) => {
            step.classList.toggle("is-complete", index < currentStage);
            step.classList.toggle("is-current", index === currentStage);
        });
        const times = {
            created: task.createdAt,
            assigned: task.assigneeId ? task.startedAt || task.createdAt : null,
            started: task.startedAt,
            submitted: task.submittedAt,
            accepted: task.acceptedAt,
            closed: task.closedAt
        };
        Object.entries(times).forEach(([key, value]) => {
            const element = panel.querySelector(`[data-status-time="${key}"]`);
            if (element) element.textContent = value ? formatDateTime(value).split(", ").pop() : "—";
        });
    }

    function renderChecklist(panel, task) {
        const list = panel.querySelector("[data-task-checklist]");
        const empty = panel.querySelector("[data-task-checklist-empty]");
        if (!list) return;
        clearNode(list);
        const items = Array.isArray(task.checklist) ? task.checklist : [];
        const done = items.filter((item) => item.completed || item.done || item.isCompleted).length;
        setText(panel, '[data-task-detail="checklist-done"]', String(done));
        setText(panel, '[data-task-detail="checklist-total"]', String(items.length));
        if (empty) empty.hidden = items.length > 0;
        items.forEach((item) => {
            const fragment = cloneTemplate("checklist-item-template");
            const checkbox = fragment?.querySelector("[data-checklist-complete]");
            const title = fragment?.querySelector("[data-checklist-title]");
            if (!checkbox || !title) return;
            checkbox.checked = Boolean(item.completed || item.done || item.isCompleted);
            title.textContent = item.title || item.text || item.name || "Punkt";
            list.append(fragment);
        });
    }

    function renderHistory(panel, task) {
        const list = panel.querySelector("[data-task-history]");
        if (!list) return;
        clearNode(list);
        const activity = getTaskActivity(task);
        activity.forEach((item) => {
            const fragment = cloneTemplate("history-item-template");
            if (!fragment) return;
            setText(fragment, "[data-history-title]", item.title);
            setText(fragment, "[data-history-description]", item.description);
            setText(fragment, "[data-history-time]", formatDateTime(item.time));
            list.append(fragment);
        });
        setText(panel, '[data-task-detail="history-count"]', String(activity.length));
        setText(panel, '[data-task-detail="history-count-side"]', String(activity.length));
    }

    function renderComments(panel, task) {
        const list = panel.querySelector("[data-task-comments]");
        const empty = panel.querySelector("[data-task-comments-empty]");
        if (list) clearNode(list);
        const comments = Array.isArray(task.comments) ? task.comments : [];
        setText(panel, '[data-task-detail="comment-count"]', String(comments.length));
        setText(panel, '[data-task-detail="comment-count-side"]', String(comments.length));
        if (empty) empty.hidden = comments.length > 0;
    }

    function renderTaskDetail() {
        const panel = document.getElementById("task-detail-panel");
        const backdrop = document.getElementById("task-detail-backdrop");
        if (!panel) return;
        const task = state.tasks.find((item) => String(item.id) === String(state.selectedTaskId));
        if (!task) {
            panel.hidden = true;
            if (backdrop) backdrop.hidden = true;
            document.body.classList.remove("has-task-detail");
            return;
        }
        const group = (db.groups || []).find((item) => String(item.id) === String(task.groupId));
        const creatorName = personName(creatorId(task));
        const source = task.source || {};
        setText(panel, '[data-task-detail="number"]', task.number || task.id);
        setText(panel, '[data-task-detail="group"]', group?.title || "Ishlar boshqarmasi");
        setText(panel, '[data-task-detail="title"]', task.title);
        setText(panel, '[data-task-detail="title-chain"]', task.title);
        setText(panel, '[data-task-detail="number-chain"]', task.number || task.id);
        setText(panel, '[data-task-detail="description"]', task.description || "Tavsif kiritilmagan.");
        setText(panel, '[data-task-detail="status"]', normalizeStatus(task.status));
        setText(panel, '[data-task-detail="status-side"]', normalizeStatus(task.status));
        setData(panel, '[data-task-detail="status"]', "status", task.status || "");
        setData(panel, '[data-task-detail="status-side"]', "status", task.status || "");
        setText(panel, '[data-task-detail="priority"]', normalizePriority(task.priority));
        setText(panel, '[data-task-detail="priority-side"]', normalizePriority(task.priority));
        setData(panel, '[data-task-detail="priority"]', "priority", task.priority || "NORMAL");
        setData(panel, '[data-task-detail="priority-side"]', "priority", task.priority || "NORMAL");
        const confidence = Number(source.conf || task.confidence || 0);
        setText(panel, '[data-task-detail="confidence"]', confidence ? `taxminiy ijrochi ${Math.round(confidence * 100)}%` : "aniq biriktirildi");
        setText(panel, '[data-task-detail="source-kind"]', source.kind === "VOICE" ? "Ovoz" : source.kind === "FILE" ? "Fayl" : "Matn");
        setText(panel, '[data-task-detail="source-text"]', source.text || task.description || "Manba xabari mavjud emas.");
        setText(panel, '[data-task-detail="creator"]', creatorName);
        setText(panel, '[data-task-detail="creator-side"]', creatorName);
        setText(panel, '[data-task-detail="creator-initials"]', initialsFromName(creatorName));
        setText(panel, '[data-task-detail="creator-side-initials"]', initialsFromName(creatorName));
        setText(panel, '[data-task-detail="assignee"]', personName(task.assigneeId));
        setText(panel, '[data-task-detail="confidence-value"]', confidence ? `${Math.round(confidence * 100)}%` : "100%");
        const dateTargets = [
            ['[data-task-detail="due-date"]', task.deadline, formatDate],
            ['[data-task-detail="created-at"]', task.createdAt, formatDateTime],
            ['[data-task-detail="created-at-side"]', task.createdAt, formatDateTime]
        ];
        dateTargets.forEach(([selector, value, formatter]) => {
            const element = panel.querySelector(selector);
            if (element) {
                element.textContent = formatter(value);
                element.dateTime = value ? new Date(value).toISOString() : "";
            }
        });
        const deadlineCard = panel.querySelector("[data-deadline-card]");
        if (deadlineCard) deadlineCard.dataset.deadlineState = deadlineState(task);
        setText(panel, '[data-task-detail="deadline-label"]', deadlineText(task));
        setText(panel, '[data-task-detail="deadline-value"]', task.deadline ? formatDateTime(task.deadline) : "SLA hisoblanmaydi");
        const chainSummary = panel.querySelector("[data-task-chain-summary]");
        if (chainSummary) chainSummary.textContent = task.parentId ? `Ota topshiriq: ${task.parentId}` : "Ushbu topshiriq mustaqil topshiriq.";
        updateStatusLine(panel, task);
        renderChecklist(panel, task);
        renderComments(panel, task);
        renderHistory(panel, task);
        panel.hidden = false;
        if (backdrop) backdrop.hidden = false;
        document.body.classList.add("has-task-detail");
    }

    function updateTaskInsights(tasks) {
        const alert = document.querySelector("[data-task-alert]");
        const paused = tasks.filter((task) => task.status === "PAUSED").length;
        if (alert) alert.textContent = `${paused || 1} ta topshiriq to‘xtatilgan`;
    }

    function getQuickFilteredTasks(tasks) {
        if (state.quickFilter === "mine") return tasks.filter((task) => String(task.assigneeId) === "p1");
        if (state.quickFilter === "pending") return tasks.filter((task) => ["SUBMITTED", "REVIEW"].includes(task.status));
        if (state.quickFilter === "risk") return tasks.filter((task) => ["overdue", "urgent", "warning"].includes(deadlineState(task)));
        if (state.quickFilter === "unassigned") return tasks.filter((task) => !task.assigneeId);
        return tasks;
    }

    function renderTasks() {
        const filtered = getFilteredTasks();
        const tasks = getQuickFilteredTasks(filtered);
        const summary = document.querySelector("[data-task-summary]");
        const empty = document.getElementById("tasks-empty-state");
        const count = document.getElementById("active-filter-count");
        const activeFilterCount = Object.values(state.filters).reduce((total, value) => total + (Array.isArray(value) ? value.length : value ? 1 : 0), 0);
        if (summary) summary.textContent = `${state.tasks.length} topshiriq · ${tasks.filter((task) => !isCompleted(task)).length} faol · ${tasks.filter((task) => ["SUBMITTED", "REVIEW"].includes(task.status)).length} qabul kutmoqda`;
        if (count) count.textContent = activeFilterCount ? `${activeFilterCount} ta faol filtr` : "Faol filtr yo‘q";
        if (empty) empty.hidden = tasks.length > 0;
        updateTaskInsights(tasks);
        renderKanban(tasks);
        renderTable(tasks);
        renderTaskDetail();
    }

    function updateView() {
        const kanban = document.getElementById("kanban-view");
        const table = document.getElementById("table-view");
        if (kanban) kanban.hidden = state.currentView !== "kanban";
        if (table) table.hidden = state.currentView !== "table";
        document.querySelectorAll('[data-action="change-view"]').forEach((button) => {
            const active = button.dataset.view === state.currentView;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-pressed", String(active));
        });
    }

    function resetFilters() {
        state.filters = { status: "", priority: "", assignee: "", creator: "", dueDate: "", dueDateFrom: "", dueDateTo: "", search: "" };
        state.quickFilter = "all";
        state.tablePage = 1;
        document.querySelectorAll("[data-filter]").forEach((control) => {
            control.value = "";
        });
        document.querySelectorAll("[data-quick-filter]").forEach((button) => button.classList.toggle("is-active", button.dataset.quickFilter === "all"));
        renderTasks();
    }

    function updateDetailTab(tabName) {
        document.querySelectorAll("[data-detail-panel]").forEach((panel) => {
            const active = panel.dataset.detailPanel === tabName;
            panel.hidden = !active;
            panel.classList.toggle("is-active", active);
        });
        document.querySelectorAll('[data-action="detail-tab"]').forEach((button) => {
            const active = button.dataset.detailTab === tabName;
            button.classList.toggle("is-active", active);
            button.setAttribute("aria-selected", String(active));
        });
    }

    function bindTasks() {
        const statuses = [...new Set(state.tasks.map((task) => task.status).filter(Boolean))];
        const priorities = [...new Set(state.tasks.map((task) => task.priority).filter(Boolean))];
        const people = (db.persons || []).filter((person) => !person.mergedInto).map((person) => ({ value: person.id, label: person.fullName }));
        populateSelect(document.getElementById("task-status-filter"), statuses.map((status) => ({ value: status, label: normalizeStatus(status) })));
        populateSelect(document.getElementById("task-priority-filter"), priorities.map((priority) => ({ value: priority, label: normalizePriority(priority) })));
        populateSelect(document.getElementById("task-assignee-filter"), [{ value: "unassigned", label: "Biriktirilmagan" }, ...people]);
        populateSelect(document.getElementById("task-creator-filter"), people);

        document.querySelectorAll("[data-filter]").forEach((control) => {
            const updateFilter = () => {
                const key = control.dataset.filter;
                state.filters[key] = control.value;
                state.tablePage = 1;
                renderTasks();
            };
            control.addEventListener("change", updateFilter);
            if (control.type === "search") control.addEventListener("input", updateFilter);
        });

        document.querySelectorAll("[data-quick-filter]").forEach((button) => {
            button.addEventListener("click", () => {
                state.quickFilter = button.dataset.quickFilter;
                state.tablePage = 1;
                document.querySelectorAll("[data-quick-filter]").forEach((item) => item.classList.toggle("is-active", item === button));
                renderTasks();
            });
        });

        document.querySelectorAll('[data-action="change-view"]').forEach((button) => {
            button.addEventListener("click", () => {
                state.currentView = button.dataset.view;
                updateView();
            });
        });

        document.querySelector('[data-action="toggle-filters"]')?.addEventListener("click", () => {
            const filters = document.getElementById("tasks-filters");
            if (filters) filters.hidden = !filters.hidden;
        });
        document.querySelector('[data-action="clear-filters"]')?.addEventListener("click", resetFilters);
        document.querySelectorAll('[data-action="open-create-task"]').forEach((button) => button.addEventListener("click", () => {
            window.alert("Yangi topshiriq formasi backend bilan integratsiya qilish uchun tayyor.");
        }));
        document.querySelector('[data-action="focus-alert-task"]')?.addEventListener("click", () => {
            const task = state.tasks.find((item) => item.status === "PAUSED") || state.tasks[0];
            if (!task) return;
            state.selectedTaskId = task.id;
            renderTaskDetail();
        });

        document.addEventListener("click", (event) => {
            const trigger = event.target.closest("[data-task-id]");
            if (!trigger || event.target.closest("button")) return;
            state.selectedTaskId = trigger.dataset.taskId;
            renderTaskDetail();
        });

        document.addEventListener("keydown", (event) => {
            const trigger = event.target.closest("[data-task-id]");
            if (!trigger || !["Enter", " "].includes(event.key)) return;
            event.preventDefault();
            state.selectedTaskId = trigger.dataset.taskId;
            renderTaskDetail();
        });

        document.querySelectorAll('[data-action="close-task"]').forEach((button) => button.addEventListener("click", () => {
            state.selectedTaskId = null;
            renderTaskDetail();
        }));

        document.querySelectorAll('[data-action="detail-tab"]').forEach((button) => button.addEventListener("click", () => updateDetailTab(button.dataset.detailTab)));
        document.querySelector('[data-action="table-prev"]')?.addEventListener("click", () => {
            state.tablePage = Math.max(1, state.tablePage - 1);
            renderTable(getQuickFilteredTasks(getFilteredTasks()));
        });
        document.querySelector('[data-action="table-next"]')?.addEventListener("click", () => {
            const total = getQuickFilteredTasks(getFilteredTasks()).length;
            const totalPages = Math.max(1, Math.ceil(total / state.tablePageSize));
            state.tablePage = Math.min(totalPages, state.tablePage + 1);
            renderTable(getQuickFilteredTasks(getFilteredTasks()));
        });
        document.addEventListener("click", (event) => {
            const button = event.target.closest('[data-action="table-page"]');
            if (!button) return;
            state.tablePage = Number(button.dataset.page) || 1;
            renderTable(getQuickFilteredTasks(getFilteredTasks()));
        });
        document.querySelector('[data-action="comment-form"]')?.addEventListener("submit", (event) => {
            event.preventDefault();
            const textarea = event.currentTarget.querySelector("textarea");
            if (textarea) textarea.value = "";
        });
        document.querySelector('[data-action="start-task"]')?.addEventListener("click", () => {
            const task = state.tasks.find((item) => String(item.id) === String(state.selectedTaskId));
            if (!task) return;
            task.status = "IN_PROGRESS";
            task.startedAt = task.startedAt || new Date().toISOString();
            renderTasks();
        });

        updateView();
        renderTasks();
    }

    function renderIndexSummary() {
        const total = state.tasks.length;
        const active = state.tasks.filter((task) => !isCompleted(task)).length;
        const people = (db.persons || []).filter((person) => !person.mergedInto).length;
        const values = { total, active, people };
        Object.entries(values).forEach(([key, value]) => {
            const element = document.querySelector(`[data-dashboard="${key}"]`);
            if (element) element.textContent = String(value);
        });
    }

    function initials(name) {
        return String(name || "?").split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0)).join("").toUpperCase();
    }

    function getPersonGroups(person) {
        const groups = [];
        const assigned = state.tasks.filter((task) => String(task.assigneeId) === String(person.id));
        if (assigned.length) groups.push("Ishlar boshqarmasi · Xodim");
        if (person.systemUser) groups.push("IB monitoring · Xodim");
        return groups.length ? groups.slice(0, 2) : ["Biriktirilmagan"];
    }

    function getFilteredPeople() {
        const query = document.getElementById("people-search")?.value.trim().toLowerCase() || "";
        const status = document.getElementById("people-status-filter")?.value || "all";
        return (db.persons || []).filter((person) => {
            if (person.mergedInto) return false;
            const haystack = [person.fullName, person.username, person.tgId, person.post, ...(person.aliases || [])]
                .filter(Boolean)
                .join(" ")
                .toLowerCase();
            const statusMatch = status === "all" || person.status === status;
            return statusMatch && haystack.includes(query);
        });
    }

    function appendTemplateValue(container, templateId, selector, value) {
        const fragment = cloneTemplate(templateId);
        if (!fragment) return;
        setText(fragment, selector, value);
        container.append(fragment);
    }

    function renderPeoplePagination(total) {
        const pagination = document.getElementById("people-pagination");
        const summary = document.getElementById("people-pagination-summary");
        if (!pagination) return;
        clearNode(pagination);
        const totalPages = Math.max(1, Math.ceil(total / state.peoplePageSize));
        state.peoplePage = Math.min(state.peoplePage, totalPages);
        const start = total ? ((state.peoplePage - 1) * state.peoplePageSize) + 1 : 0;
        const end = Math.min(state.peoplePage * state.peoplePageSize, total);
        if (summary) summary.textContent = total ? `${start}–${end} / ${total} ta xodim` : "0 ta xodim";

        for (let pageNumber = 1; pageNumber <= totalPages; pageNumber += 1) {
            const fragment = cloneTemplate("pagination-button-template");
            if (!fragment) continue;
            const button = fragment.querySelector('[data-action="change-people-page"]');
            if (button) {
                button.dataset.page = String(pageNumber);
                button.textContent = String(pageNumber);
                button.classList.toggle("is-active", pageNumber === state.peoplePage);
            }
            pagination.append(fragment);
        }
    }

    function renderPeople() {
        const list = document.getElementById("people-list");
        if (!list) return;
        const people = getFilteredPeople();
        const totalPages = Math.max(1, Math.ceil(people.length / state.peoplePageSize));
        state.peoplePage = Math.min(state.peoplePage, totalPages);
        const offset = (state.peoplePage - 1) * state.peoplePageSize;
        const pagePeople = people.slice(offset, offset + state.peoplePageSize);

        clearNode(list);
        pagePeople.forEach((person) => {
            const fragment = cloneTemplate("person-row-template");
            if (!fragment) return;
            const row = fragment.querySelector(".person-row");
            if (row) row.dataset.personId = person.id;
            setText(fragment, '[data-field="initials"]', initials(person.fullName));
            setText(fragment, '[data-field="name"]', person.fullName);
            setText(fragment, '[data-field="meta"]', `${person.username ? `@${person.username}` : "username yo‘q"} · ${person.post || "Xodim"}`);
            setText(fragment, '[data-field="telegram-id"]', person.tgId ? String(person.tgId).replace(/(\d{3})(?=\d)/g, "$1 ") : "aniqlanmagan");

            const aliases = fragment.querySelector('[data-field="aliases"]');
            if (aliases) {
                clearNode(aliases);
                (person.aliases || []).slice(0, 4).forEach((alias) => appendTemplateValue(aliases, "person-alias-template", '[data-field="alias"]', alias));
            }
            const groups = fragment.querySelector('[data-field="groups"]');
            if (groups) {
                clearNode(groups);
                getPersonGroups(person).forEach((group) => appendTemplateValue(groups, "person-group-template", '[data-field="group"]', group));
            }
            const status = fragment.querySelector('[data-field="status"]');
            if (status) {
                status.textContent = person.status === "ACTIVE" ? "Faol" : "Tasdiq kutilmoqda";
                status.classList.toggle("is-pending", person.status !== "ACTIVE");
            }
            list.append(fragment);
        });

        const summary = document.getElementById("people-summary");
        const empty = document.getElementById("people-empty");
        if (summary) summary.textContent = `${people.length} ta shaxs · ${people.filter((person) => person.status === "ACTIVE").length} faol · ${(db.persons || []).filter((person) => person.mergedInto).length} ehtimoliy dublikat`;
        if (empty) empty.hidden = people.length > 0;
        renderPeoplePagination(people.length);
    }

    function getPersonMemberships(personId) {
        return (db.members || []).filter((member) => member.personId === personId && member.active);
    }

    function getPersonTasks(personId) {
        return (db.tasks || []).filter((task) => task.assigneeId === personId && !task.archived);
    }

    function roleLabel(role) {
        const labels = { HEAD: "Boshliq", EXECUTOR: "Xodim", OBSERVER: "Kuzatuvchi" };
        return labels[role] || "Xodim";
    }

    function setWidth(container, selector, value) {
        const element = container.querySelector(selector);
        if (element) element.style.width = `${Math.max(0, Math.min(100, value))}%`;
    }

    function personPerformance(personId) {
        const tasks = getPersonTasks(personId);
        const completed = tasks.filter(isCompleted);
        const onTime = completed.length
            ? Math.round((completed.filter((task) => task.deadline && task.acceptedAt && new Date(task.acceptedAt) <= new Date(task.deadline)).length / completed.length) * 100)
            : 0;
        const withoutRework = completed.length
            ? Math.round((completed.filter((task) => Number(task.reworks || 0) === 0).length / completed.length) * 100)
            : 0;
        const volume = Math.min(100, Math.round((tasks.length / 10) * 100));
        const speed = completed.length
            ? Math.round(completed.reduce((total, task) => total + (task.startedAt ? 80 : 55), 0) / completed.length)
            : 0;
        const score = Math.round((onTime * .4) + (withoutRework * .3) + (volume * .2) + (speed * .1));
        return { score, onTime, withoutRework, volume, speed };
    }

    function renderPersonProfileGroups(person) {
        const container = document.getElementById("person-profile-groups");
        if (!container) return;
        clearNode(container);
        const memberships = getPersonMemberships(person.id);
        memberships.forEach((membership) => {
            const fragment = cloneTemplate("person-profile-group-template");
            if (!fragment) return;
            const group = (db.groups || []).find((item) => item.id === membership.groupId);
            const groupTasks = (db.tasks || []).filter((task) => task.groupId === membership.groupId && task.assigneeId === person.id && !task.archived);
            const completed = groupTasks.filter(isCompleted);
            const timely = completed.length
                ? Math.round((completed.filter((task) => task.deadline && task.acceptedAt && new Date(task.acceptedAt) <= new Date(task.deadline)).length / completed.length) * 100)
                : 0;
            setText(fragment, '[data-field="group-name"]', group?.title || "Guruh");
            setText(fragment, '[data-field="group-date"]', membership.joined || "—");
            setText(fragment, '[data-field="group-task-count"]', String(groupTasks.length));
            setText(fragment, '[data-field="group-timeliness-value"]', `${timely}%`);
            setWidth(fragment, '[data-field="group-timeliness-bar"]', timely);
            const roles = fragment.querySelector('[data-field="profile-roles"]');
            if (roles) {
                ["HEAD", "EXECUTOR", "OBSERVER"].forEach((role) => {
                    const roleFragment = cloneTemplate("person-profile-role-template");
                    if (!roleFragment) return;
                    const chip = roleFragment.querySelector('[data-field="profile-role"]');
                    if (chip) {
                        chip.textContent = roleLabel(role);
                        chip.classList.toggle("is-active", role === membership.role);
                    }
                    roles.append(roleFragment);
                });
            }
            container.append(fragment);
        });
    }

    function renderPersonProfileAliases(person) {
        const container = document.getElementById("person-profile-aliases");
        if (!container) return;
        clearNode(container);
        (person.aliases || []).forEach((alias) => appendTemplateValue(container, "person-profile-alias-template", '[data-field="profile-alias"]', alias));
    }

    function openPersonModal(personId) {
        const person = getPerson(personId);
        const modal = document.getElementById("person-modal");
        if (!person || !modal) return;
        const performance = personPerformance(person.id);
        const scoreLabel = performance.score >= 75 ? "barqaror sifat" : performance.score >= 50 ? "yaxshilanish mumkin" : "e’tibor talab";
        const values = {
            "modal-initials": initials(person.fullName),
            "modal-name": person.fullName || "—",
            "modal-status": person.status === "ACTIVE" ? "Faol" : "Tasdiq kutilmoqda",
            "modal-subtitle": `${person.post || "Xodim"} · ${getPersonMemberships(person.id).length} guruh a’zosi`,
            "profile-tg-id": person.tgId ? String(person.tgId).replace(/(\d{3})(?=\d)/g, "$1 ") : "aniqlanmagan",
            "profile-username": person.username ? `@${person.username}` : "username yo‘q",
            "profile-post": person.post || "—",
            "profile-first-seen": person.firstSeen || "—",
            "profile-score": String(performance.score),
            "profile-score-label": scoreLabel,
            "metric-ontime-value": `${performance.onTime} → ${Math.round(performance.onTime * .4)}`,
            "metric-rework-value": `${performance.withoutRework} → ${Math.round(performance.withoutRework * .3)}`,
            "metric-volume-value": `${performance.volume} → ${Math.round(performance.volume * .2)}`,
            "metric-speed-value": `${performance.speed} → ${Math.round(performance.speed * .1)}`
        };
        Object.entries(values).forEach(([field, value]) => setText(modal, `[data-field="${field}"]`, value));
        setWidth(modal, '[data-field="profile-score-bar"]', performance.score);
        setWidth(modal, '[data-field="metric-ontime-bar"]', performance.onTime);
        setWidth(modal, '[data-field="metric-rework-bar"]', performance.withoutRework);
        setWidth(modal, '[data-field="metric-volume-bar"]', performance.volume);
        setWidth(modal, '[data-field="metric-speed-bar"]', performance.speed);
        renderPersonProfileGroups(person);
        renderPersonProfileAliases(person);
        modal.hidden = false;
        document.body.classList.add("is-modal-open");
        modal.querySelector('[data-action="close-person-modal"]')?.focus();
    }

    function closePersonModal() {
        const modal = document.getElementById("person-modal");
        if (modal) modal.hidden = true;
        document.body.classList.remove("is-modal-open");
    }

    function bindPeople() {
        document.querySelector('[data-action="search-people"]')?.addEventListener("input", () => {
            state.peoplePage = 1;
            renderPeople();
        });
        document.querySelector('[data-action="filter-people"]')?.addEventListener("change", () => {
            state.peoplePage = 1;
            renderPeople();
        });
        document.getElementById("people-pagination")?.addEventListener("click", (event) => {
            const button = event.target.closest('[data-action="change-people-page"]');
            if (!button) return;
            state.peoplePage = Number(button.dataset.page) || 1;
            renderPeople();
        });
        document.getElementById("people-list")?.addEventListener("click", (event) => {
            const action = event.target.closest("[data-action]");
            const row = event.target.closest(".person-row");
            if (!action || !row) return;
            if (action.dataset.action === "edit-person" || action.dataset.action === "open-person-profile") openPersonModal(row.dataset.personId);
        });
        document.querySelectorAll('[data-action="close-person-modal"]').forEach((button) => button.addEventListener("click", closePersonModal));
        document.getElementById("person-modal")?.addEventListener("click", (event) => {
            if (event.target.id === "person-modal") closePersonModal();
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") closePersonModal();
        });
        renderPeople();
    }

    function renderReports() {
        const total = state.tasks.length;
        const completed = state.tasks.filter(isCompleted).length;
        const overdue = state.tasks.filter((task) => isOverdue(task)).length;
        const unassigned = state.tasks.filter((task) => !task.assigneeId).length;
        const values = { total, overdue, unassigned, completion: total ? `${Math.round((completed / total) * 100)}%` : "0%" };
        Object.entries(values).forEach(([key, value]) => {
            const element = document.querySelector(`[data-report="${key}"]`);
            if (element) element.textContent = String(value);
        });

        const list = document.getElementById("report-status-list");
        if (!list) return;
        clearNode(list);
        const statuses = [...new Set(state.tasks.map((task) => task.status).filter(Boolean))];
        statuses.forEach((status) => {
            const count = state.tasks.filter((task) => task.status === status).length;
            const fragment = cloneTemplate("report-status-template");
            if (!fragment) return;
            setText(fragment, '[data-field="status"]', normalizeStatus(status));
            setText(fragment, '[data-field="count"]', String(count));
            const meter = fragment.querySelector('[data-field="meter"]');
            if (meter) meter.style.width = `${total ? Math.round((count / total) * 100) : 0}%`;
            list.append(fragment);
        });
    }

    function renderChain() {
        const list = document.getElementById("chain-list");
        if (!list) return;
        clearNode(list);
        state.tasks.forEach((task) => {
            const fragment = cloneTemplate("chain-item-template");
            const item = fragment?.querySelector("[data-task-id]");
            if (!item) return;
            item.dataset.taskId = task.id;
            setText(item, '[data-field="title"]', task.title);
            setText(item, '[data-field="number"]', task.number || task.id);
            setText(item, '[data-field="relation"]', task.parentId ? `Child of ${task.parentId}` : "Root task");
            setText(item, '[data-field="status"]', normalizeStatus(task.status));
            list.append(fragment);
        });
    }

    function formatAuditDate(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return "—";
        return new Intl.DateTimeFormat("uz-UZ", {
            day: "2-digit",
            month: "short",
            hour: "2-digit",
            minute: "2-digit"
        }).format(date);
    }

    function renderSecurityLogs() {
        const list = document.getElementById("security-logs-list");
        if (!list) return;
        clearNode(list);
        (db.audit || []).slice(0, 5).forEach((entry) => {
            const fragment = cloneTemplate("security-log-template");
            if (!fragment) return;
            setText(fragment, '[data-field="action"]', entry.action || "Xavfsizlik amali");
            setText(fragment, '[data-field="object"]', entry.obj || "—");
            setText(fragment, '[data-field="date"]', formatAuditDate(entry.at));
            list.append(fragment);
        });
    }

    function renderSecuritySessions() {
        const list = document.getElementById("sessions-list");
        if (!list) return;
        clearNode(list);
        (db.sessions || []).forEach((session) => {
            const fragment = cloneTemplate("session-template");
            const item = fragment?.querySelector("[data-session-id]");
            if (!item) return;
            item.dataset.sessionId = session.id || "";
            setText(item, '[data-field="device"]', session.dev || session.device || "Sessiya");
            setText(item, '[data-field="location"]', session.loc || session.location || "Noma’lum joylashuv");
            setText(item, '[data-field="ip"]', session.ip || "IP noma’lum");
            setText(item, '[data-field="last-active"]', session.at || session.last || session.lastActive || "Hozir");
            const current = item.querySelector('[data-field="current"]');
            if (current) current.hidden = !session.cur;
            const removeButton = item.querySelector('[data-action="remove-session"]');
            if (removeButton && session.cur) removeButton.textContent = "Bu qurilmadan chiqish";
            list.append(fragment);
        });
    }

    function renderSecurity() {
        renderSecurityLogs();
        renderSecuritySessions();
    }

    function bindSecurity() {
        document.getElementById("security-form")?.addEventListener("submit", (event) => {
            event.preventDefault();
            const form = event.currentTarget;
            const newPassword = form.elements.new_password?.value || "";
            const confirmation = form.elements.new_password_confirmation?.value || "";
            if (newPassword !== confirmation) {
                window.alert("Yangi parol va tasdiqlash qiymati bir xil bo‘lishi kerak.");
                return;
            }
            window.alert("Demo: parolni yangilash uchun Laravel backend endpointiga ulang.");
            form.reset();
        });

        document.getElementById("sessions-list")?.addEventListener("click", (event) => {
            const button = event.target.closest('[data-action="remove-session"]');
            const sessionElement = event.target.closest("[data-session-id]");
            if (!button || !sessionElement) return;
            const sessionId = sessionElement.dataset.sessionId;
            const sessions = db.sessions || [];
            const index = sessions.findIndex((session) => String(session.id) === String(sessionId));
            if (index === -1) return;
            sessions.splice(index, 1);
            renderSecuritySessions();
        });
    }

    function bindLogin() {
        document.getElementById("login-form")?.addEventListener("submit", (event) => {
            event.preventDefault();
            window.location.href = "dashboard";
        });
    }


    const notificationState = {
        items: []
    };

    function getNotifications() {
        if (Array.isArray(db.notifications) && db.notifications.length) {
            return db.notifications.map((item, index) => ({
                id: item.id || `notification-${index}`,
                title: item.title || "Yangi bildirishnoma",
                message: item.message || item.body || "Tizimda yangi yangilanish mavjud.",
                time: item.time || item.at || "Hozirgina",
                read: Boolean(item.read)
            }));
        }

        const tasks = Array.isArray(db.tasks) ? db.tasks.slice(0, 4) : [];
        return tasks.map((task, index) => ({
            id: `task-${task.id || index}`,
            title: task.title || "Topshiriq yangilandi",
            message: `${normalizeStatus(task.status)} · ${task.number || "Topshiriq"}`,
            time: task.updatedAt || task.createdAt || "Hozirgina",
            read: index > 1
        }));
    }

    function renderNotifications() {
        const list = document.getElementById("notification-list");
        if (!list) return;
        if (!notificationState.items.length) notificationState.items = getNotifications();
        clearNode(list);
        notificationState.items.forEach((notification) => {
            const fragment = cloneTemplate("notification-item-template");
            const item = fragment?.querySelector("[data-notification-id]");
            if (!item) return;
            item.dataset.notificationId = notification.id;
            item.dataset.notificationRead = String(Boolean(notification.read));
            setText(item, '[data-field="title"]', notification.title);
            setText(item, '[data-field="message"]', notification.message);
            setText(item, '[data-field="time"]', notification.time);
            list.append(fragment);
        });
        updateNotificationCount();
    }

    function updateNotificationCount() {
        const unread = notificationState.items.filter((item) => !item.read).length;
        document.querySelectorAll(".notification-count").forEach((element) => {
            element.textContent = unread;
            element.hidden = unread === 0;
        });
    }

    function closeNotifications() {
        const card = document.getElementById("notification-card");
        const trigger = document.querySelector('[data-action="toggle-notifications"]');
        if (card) card.hidden = true;
        if (trigger) trigger.setAttribute("aria-expanded", "false");
    }

    function bindNotifications() {
        const card = document.getElementById("notification-card");
        const trigger = document.querySelector('[data-action="toggle-notifications"]');
        if (!card || !trigger) return;
        renderNotifications();

        trigger.addEventListener("click", (event) => {
            event.stopPropagation();
            const nextHidden = !card.hidden;
            card.hidden = nextHidden;
            trigger.setAttribute("aria-expanded", String(!nextHidden));
            if (!nextHidden) renderNotifications();
        });

        card.addEventListener("click", (event) => {
            const dismiss = event.target.closest('[data-action="dismiss-notification"]');
            const markRead = event.target.closest('[data-action="mark-notifications-read"]');
            if (markRead) {
                notificationState.items.forEach((item) => { item.read = true; });
                renderNotifications();
                return;
            }
            if (!dismiss) return;
            const item = dismiss.closest("[data-notification-id]");
            if (!item) return;
            notificationState.items = notificationState.items.filter((entry) => String(entry.id) !== String(item.dataset.notificationId));
            renderNotifications();
        });

        document.addEventListener("click", (event) => {
            if (card.hidden) return;
            if (!card.contains(event.target) && !trigger.contains(event.target)) closeNotifications();
        });
        document.addEventListener("keydown", (event) => {
            if (event.key === "Escape") closeNotifications();
        });
    }

    function applyTheme(theme) {
        const root = document.documentElement;
        root.dataset.theme = theme;
        try { window.localStorage.setItem("imv-theme", theme); } catch (_) {}
    }

    function initializeTheme() {
        let theme = "";
        try { theme = window.localStorage.getItem("imv-theme") || ""; } catch (_) {}
        if (!theme) theme = window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches ? "dark" : "light";
        applyTheme(theme);
    }

    function bindGlobalBehavior() {
        document.querySelectorAll('[data-action="toggle-theme"]').forEach((button) => {
            button.addEventListener("click", () => {
                const root = document.documentElement;
                applyTheme(root.dataset.theme === "dark" ? "light" : "dark");
            });
        });
        document.querySelector('[data-action="global-search"]')?.addEventListener("input", (event) => {
            if (page !== "tasks") return;
            const search = document.getElementById("task-search");
            if (!search) return;
            search.value = event.target.value;
            state.filters.search = event.target.value;
            renderTasks();
        });
        document.querySelector('[data-action="refresh-reports"]')?.addEventListener("click", renderReports);
    }

    function initialize() {
        initializeTheme();
        state.tasks = Array.isArray(db.tasks) ? db.tasks.slice() : [];
        bindGlobalBehavior();
        bindNotifications();
        if (page === "tasks") bindTasks();
        if (page === "index") renderIndexSummary();
        if (page === "people") bindPeople();
        if (page === "reports") renderReports();
        if (page === "chain") renderChain();
        if (page === "security") {
            renderSecurity();
            bindSecurity();
        }
        if (page === "login") bindLogin();
    }

    document.addEventListener("DOMContentLoaded", initialize);
})();
