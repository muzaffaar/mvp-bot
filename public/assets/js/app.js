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

    /*
    |--------------------------------------------------------------------------
    | Backend API helpers
    |--------------------------------------------------------------------------
    |
    | Shared by every real (non-demo) AJAX call in this file: task actions,
    | task creation, comments.
    |
    */

    function csrfToken() {
        return (
            document.querySelector('meta[name="csrf-token"]')
                ?.content || ""
        );
    }

    function showToast(message, variant) {
        const region = document.getElementById("toast-region");

        if (!region) {
            window.alert(message);
            return;
        }

        const toast = document.createElement("div");
        toast.className = `toast toast--${variant || "success"}`;
        toast.textContent = message;
        region.appendChild(toast);

        requestAnimationFrame(() => {
            toast.classList.add("is-visible");
        });

        setTimeout(() => {
            toast.classList.remove("is-visible");
            setTimeout(() => toast.remove(), 300);
        }, 4500);
    }

    async function apiRequest(url, options) {
        options = options || {};

        const response = await fetch(url, {
            method: options.method || "GET",
            headers: Object.assign(
                {
                    "X-CSRF-TOKEN": csrfToken(),
                    "X-Requested-With": "XMLHttpRequest",
                    Accept: "application/json",
                },
                options.body
                    ? { "Content-Type": "application/json" }
                    : {}
            ),
            body: options.body
                ? JSON.stringify(options.body)
                : undefined,
        });

        let payload = null;

        try {
            payload = await response.json();
        } catch (error) {
            payload = null;
        }

        if (!response.ok) {
            const validationMessage =
                payload && payload.errors
                    ? Object.values(payload.errors)
                        .flat()
                        .join(" ")
                    : null;

            throw new Error(
                validationMessage ||
                    payload?.message ||
                    `So‘rovda xatolik yuz berdi (${response.status}).`
            );
        }

        return payload;
    }

    function getPerson(id) {
        return (db.persons || []).find((person) => String(person.id) === String(id)) || null;
    }

    function personName(id) {

    if (!id) {
        return "Biriktirilmagan";
    }

    const taskPerson = state.tasks
        .flatMap((task) => [
            task.assignee
                ? {
                    id: task.assignee.id,
                    name: task.assignee.name,
                }
                : null,

            task.creator
                ? {
                    id: task.creator.id,
                    name: task.creator.name,
                }
                : null,
        ])
        .filter(Boolean)
        .find(
            (person) =>
                String(person.id) === String(id)
        );

    if (taskPerson) {
        return taskPerson.name;
    }

    const person = getPerson(id);

    return person
        ? person.fullName
        : "Noma'lum";
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
        const normalized = String(status || "").trim().toUpperCase();

        const labels = {
            CREATED: "Yangi",
            NEW: "Yangi",
            ASSIGNED: "Biriktirildi",
            ACCEPTED: "Qabul qilindi",
            IN_PROGRESS: "Jarayonda",
            PAUSED: "To‘xtatilgan",
            SUBMITTED: "Qabul kutmoqda",
            AWAITING_ACCEPTANCE: "Qabul kutmoqda",
            REVIEW: "Ko‘rib chiqilmoqda",
            COMPLETION_APPROVED: "Tasdiqlandi",
            APPROVED: "Tasdiqlandi",
            RETURNED: "Qaytarildi",
            REJECTED: "Rad etildi",
            CANCELLED: "Bekor qilindi",
            CANCELED: "Bekor qilindi",
            CLOSED: "Yopildi"
        };

        return labels[normalized] || normalized || "Noma’lum";
    }

    function normalizePriority(priority) {
        const normalized = String(priority || "NORMAL").trim().toUpperCase();

        const labels = {
            CRITICAL: "Juda yuqori",
            URGENT: "Juda yuqori",
            HIGH: "Yuqori",
            MEDIUM: "O‘rtacha",
            NORMAL: "Oddiy",
            LOW: "Past"
        };

        return labels[normalized] || "Oddiy";
    }

    function statusBadgeValue(status) {
        const normalized = String(status || "").trim().toUpperCase();

        const aliases = {
            CREATED: "NEW",
            NEW: "NEW",
            ASSIGNED: "ASSIGNED",
            ACCEPTED: "ACCEPTED",
            IN_PROGRESS: "IN_PROGRESS",
            PAUSED: "PAUSED",
            SUBMITTED: "SUBMITTED",
            AWAITING_ACCEPTANCE: "SUBMITTED",
            REVIEW: "REVIEW",
            COMPLETION_APPROVED: "APPROVED",
            APPROVED: "APPROVED",
            RETURNED: "RETURNED",
            REJECTED: "REJECTED",
            CANCELLED: "CANCELLED",
            CANCELED: "CANCELLED",
            CLOSED: "CLOSED"
        };

        return aliases[normalized] || normalized || "NEW";
    }

    function priorityBadgeValue(priority) {
        const normalized = String(priority || "NORMAL").trim().toUpperCase();

        const aliases = {
            URGENT: "CRITICAL",
            CRITICAL: "CRITICAL",
            HIGH: "HIGH",
            MEDIUM: "MEDIUM",
            NORMAL: "NORMAL",
            LOW: "LOW"
        };

        return aliases[normalized] || "NORMAL";
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
        const buckets = {
            CREATED: "NEW",
            NEW: "NEW",

            ASSIGNED: "ASSIGNED",

            ACCEPTED: "ACCEPTED",

            IN_PROGRESS: "IN_PROGRESS",
            PAUSED: "IN_PROGRESS",

            AWAITING_ACCEPTANCE: "SUBMITTED",
            SUBMITTED: "SUBMITTED",
            REVIEW: "SUBMITTED",

            COMPLETION_APPROVED: "APPROVED",
            APPROVED: "APPROVED",

            CLOSED: "CLOSED"
        };

        return buckets[status] || "NEW";
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
    const visibleTaskIds = new Set(
        tasks.map((task) => String(task.id))
    );

    document
        .querySelectorAll("#kanban-view [data-task-id]")
        .forEach((card) => {
            const taskId = String(card.dataset.taskId);

            card.hidden = !visibleTaskIds.has(taskId);
        });

    document
        .querySelectorAll("#kanban-view .kanban-column")
        .forEach((column) => {
            const status = column.dataset.status;

            const visibleCards = Array.from(
                column.querySelectorAll("[data-task-id]")
            ).filter((card) => !card.hidden);

            const count = column.querySelector(
                `[data-column-count="${status}"]`
            );

            if (count) {
                count.textContent = String(
                    visibleCards.length
                );
            }

            let empty = column.querySelector(
                "[data-js-empty-state]"
            );

            if (!empty) {
                empty = document.createElement("p");

                empty.className =
                    "kanban-column__empty";

                empty.dataset.jsEmptyState = "true";

                column
                    .querySelector(".kanban-column__tasks")
                    ?.appendChild(empty);
            }

            empty.textContent =
                "Joriy filtr bo‘yicha topshiriqlar yo‘q";

            empty.hidden =
                visibleCards.length > 0;
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
            setData(
                row,
                '[data-task-field="status"]',
                "status",
                statusBadgeValue(task.status)
            );

            setText(row, '[data-task-field="priority"]', normalizePriority(task.priority));
            setData(
                row,
                '[data-task-field="priority"]',
                "priority",
                priorityBadgeValue(task.priority)
            );
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
        const asArray = (value) => Array.isArray(value) ? value : [];

        // Always read activity from the currently selected task. Do not keep any
        // module-level/cache state here; switching tasks must produce a fresh list.
        const sources = [
            asArray(task.logs),
            asArray(task.history),
            asArray(task.activity),
        ];

        const labels = {
            created: "Topshiriq yaratildi",
            task_created: "Topshiriq yaratildi",
            assigned: "Topshiriq biriktirildi",
            task_assigned: "Topshiriq biriktirildi",
            accepted: "Topshiriq qabul qilindi",
            task_accepted: "Topshiriq qabul qilindi",
            started: "Ish boshlandi",
            in_progress: "Jarayon boshlandi",
            submitted: "Qabulga yuborildi",
            awaiting_acceptance: "Qabulga yuborildi",
            completion_approved: "Topshiriq tasdiqlandi",
            approved: "Topshiriq tasdiqlandi",
            closed: "Topshiriq yopildi",
            status_changed: "Holat o‘zgartirildi",
            assignee_changed: "Ijrochi o‘zgartirildi",
            comment_added: "Izoh qo‘shildi",
        };

        const seen = new Set();
        const result = [];

        sources.flat().forEach((log) => {
            if (!log || typeof log !== "object") return;

            const rawType = String(
                log.eventType ?? log.event_type ?? log.action ?? log.type ?? log.title ?? ""
            ).trim();

            const normalizedType = rawType
                .toLowerCase()
                .replace(/[\s-]+/g, "_");

            const description = String(
                log.message ?? log.description ?? log.text ?? log.metadata?.message ?? ""
            );

            const time =
                log.createdAt ?? log.created_at ?? log.time ?? log.date ?? null;

            const key = log.id != null
                ? `id:${log.id}`
                : `${time ?? ""}|${rawType}|${description}`;

            if (seen.has(key)) return;
            seen.add(key);

            result.push({
                title: labels[normalizedType] || rawType || "O‘zgarish",
                description: description || "Qo‘shimcha ma’lumot mavjud emas.",
                time,
            });
        });

        return result.sort((a, b) => {
            const at = a.time ? new Date(a.time).getTime() : 0;
            const bt = b.time ? new Date(b.time).getTime() : 0;
            return bt - at;
        });
    }

    function updateStatusLine(panel, task) {
        const statusOrder = [
            "created",
            "assigned",
            "accepted",
            "in_progress",
            "awaiting_acceptance",
            "completion_approved",
            "closed"
        ];

        let currentStatus = String(task.status || "")
            .trim()
            .toLowerCase();

        /*
        |--------------------------------------------------------------------------
        | Normalize possible frontend aliases
        |--------------------------------------------------------------------------
        */

        const statusAliases = {
            new: "created",
            submitted: "awaiting_acceptance",
            approved: "completion_approved"
        };

        currentStatus = statusAliases[currentStatus] || currentStatus;

        const currentIndex = statusOrder.indexOf(currentStatus);

        console.log("Task status:", task.status);
        console.log("Normalized status:", currentStatus);
        console.log("Status index:", currentIndex);
        console.log("Status dates:", task.statusDates);

        panel.querySelectorAll("[data-status-step]").forEach((step) => {

            const stepStatus = String(
                step.dataset.statusStep || ""
            )
                .trim()
                .toLowerCase();

            const stepIndex = statusOrder.indexOf(stepStatus);

            /*
            |--------------------------------------------------------------------------
            | Remove all possible state classes
            |--------------------------------------------------------------------------
            */

            step.classList.remove(
                "is-active",
                "is-current",
                "is-completed",
                "is-complete"
            );

            /*
            |--------------------------------------------------------------------------
            | Completed statuses
            |--------------------------------------------------------------------------
            */

            if (
                currentIndex !== -1 &&
                stepIndex !== -1 &&
                stepIndex < currentIndex
            ) {
                step.classList.add(
                    "is-completed",
                    "is-complete"
                );

                const dot = step.querySelector(
                    ".task-status-line__dot"
                );

                if (dot) {
                    dot.textContent = "✓";
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Current active status
            |--------------------------------------------------------------------------
            */

            if (
                currentIndex !== -1 &&
                stepIndex === currentIndex
            ) {
                step.classList.add(
                    "is-active",
                    "is-current"
                );

                const dot = step.querySelector(
                    ".task-status-line__dot"
                );

                if (dot) {
                    dot.textContent = "✓";
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Future statuses
            |--------------------------------------------------------------------------
            */

            if (
                currentIndex !== -1 &&
                stepIndex > currentIndex
            ) {
                const dot = step.querySelector(
                    ".task-status-line__dot"
                );

                if (dot) {
                    dot.textContent = "•";
                }
            }

            /*
            |--------------------------------------------------------------------------
            | Status date
            |--------------------------------------------------------------------------
            */

            const timeElement = step.querySelector(
                "[data-status-time]"
            );

            if (!timeElement) {
                return;
            }

            const dateValue =
                task.statusDates?.[stepStatus] ||
                getStatusDateFallback(task, stepStatus);

            if (!dateValue) {
                timeElement.textContent = "—";
                timeElement.removeAttribute("datetime");

                return;
            }

            const date = new Date(dateValue);

            if (Number.isNaN(date.getTime())) {
                timeElement.textContent = "—";
                timeElement.removeAttribute("datetime");

                return;
            }

            timeElement.textContent = formatDateTime(dateValue);
            timeElement.dateTime = date.toISOString();
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

    function resetTaskActivityUI(panel, taskId = null) {
        if (!panel) return;

        const historyList = panel.querySelector("[data-task-history]");
        if (historyList) {
            historyList.replaceChildren();
            if (taskId !== null) historyList.dataset.taskId = String(taskId);
            else delete historyList.dataset.taskId;
        }

        const commentsList = panel.querySelector("[data-task-comments]");
        if (commentsList) {
            commentsList.replaceChildren();
            if (taskId !== null) commentsList.dataset.taskId = String(taskId);
            else delete commentsList.dataset.taskId;
        }

        const commentsEmpty = panel.querySelector("[data-task-comments-empty]");
        if (commentsEmpty) commentsEmpty.hidden = false;

        setText(panel, '[data-task-detail="history-count"]', "0");
        setText(panel, '[data-task-detail="history-count-side"]', "0");
        setText(panel, '[data-task-detail="comment-count"]', "0");
        setText(panel, '[data-task-detail="comment-count-side"]', "0");
    }

    function renderHistory(panel, task) {
        if (!panel || !task) return;

        const list = panel.querySelector("[data-task-history]");
        if (!list) return;

        // IMPORTANT: resetTaskActivityUI() clears BOTH history and comments.
        // Calling it here would erase comments that were already rendered for
        // the current task. Reset only the history section in this renderer.
        list.replaceChildren();
        list.dataset.taskId = String(task.id);
        setText(panel, '[data-task-detail="history-count"]', "0");
        setText(panel, '[data-task-detail="history-count-side"]', "0");

        // Guard against stale render calls: only the currently selected task
        // is allowed to populate the shared history container.
        if (String(state.selectedTaskId) !== String(task.id)) {
            return;
        }

        const activity = getTaskActivity(task);

        // Verify the container still belongs to this task after the activity
        // has been calculated. This prevents a late render from Task A from
        // writing into the drawer after Task B was selected.
        if (String(list.dataset.taskId || "") !== String(task.id)) {
            return;
        }

        if (!activity.length) {
            const empty = document.createElement("li");
            empty.className = "history-empty";
            empty.dataset.emptyState = "true";
            empty.textContent = "Tarix va loglar mavjud emas.";
            list.append(empty);
            return;
        }

        const fragment = document.createDocumentFragment();

        activity.forEach((item) => {
            const itemFragment = cloneTemplate("history-item-template");
            if (!itemFragment) return;

            setText(itemFragment, "[data-history-title]", item.title);
            setText(itemFragment, "[data-history-description]", item.description);
            setText(
                itemFragment,
                "[data-history-time]",
                item.time ? formatDateTime(item.time) : "—"
            );

            fragment.append(itemFragment);
        });

        // One final stale-task check before touching the DOM.
        if (
            String(state.selectedTaskId) !== String(task.id) ||
            String(list.dataset.taskId || "") !== String(task.id)
        ) {
            return;
        }

        list.replaceChildren(fragment);

        setText(panel, '[data-task-detail="history-count"]', String(activity.length));
        setText(panel, '[data-task-detail="history-count-side"]', String(activity.length));
    }

    function escapeHtml(value) {
        return String(value ?? "")
            .replace(/&/g, "&amp;")
            .replace(/</g, "&lt;")
            .replace(/>/g, "&gt;")
            .replace(/"/g, "&quot;")
            .replace(/'/g, "&#039;");
    }

function renderComments(panel, task) {
    if (!panel || !task) return;

    const list = panel.querySelector("[data-task-comments]");
    const empty = panel.querySelector("[data-task-comments-empty]");
    if (!list) return;

    // Shared drawer: always destroy comments from the previously opened task.
    list.replaceChildren();
    list.dataset.taskId = String(task.id);
    if (empty) empty.hidden = false;
    setText(panel, '[data-task-detail="comment-count"]', "0");
    setText(panel, '[data-task-detail="comment-count-side"]', "0");

    // A stale render must never repopulate the drawer after another task opens.
    if (String(state.selectedTaskId) !== String(task.id)) return;

    // Support all comment payload aliases used by the task pages/API.
    const comments = Array.isArray(task.comments)
        ? task.comments
        : Array.isArray(task.taskComments)
            ? task.taskComments
            : Array.isArray(task.task_comments)
                ? task.task_comments
                : [];

    if (!comments.length) return;

    const fragment = document.createDocumentFragment();

    comments.forEach((comment) => {
        const commentElement = document.createElement("article");
        commentElement.className = "comment-item";

        const authorName =
            comment.staff?.name ||
            comment.author?.name ||
            comment.user?.name ||
            comment.staffName ||
            "Noma'lum foydalanuvchi";

        const rawCreatedAt =
            comment.createdAt ??
            comment.created_at ??
            comment.created ??
            null;

        const createdAt = rawCreatedAt ? formatDateTime(rawCreatedAt) : "—";
        const bodyText =
            comment.body ??
            comment.comment ??
            comment.message ??
            comment.text ??
            comment.content ??
            "";

        const header = document.createElement("header");
        header.className = "comment-item__header";

        const author = document.createElement("strong");
        author.textContent = authorName;

        const time = document.createElement("time");
        time.textContent = createdAt;

        const body = document.createElement("p");
        body.className = "comment-item__body";
        body.textContent = bodyText;

        header.append(author, time);
        commentElement.append(header, body);
        fragment.append(commentElement);
    });

    // Final stale-task/container guard before changing the shared DOM.
    if (
        String(state.selectedTaskId) !== String(task.id) ||
        String(list.dataset.taskId || "") !== String(task.id)
    ) {
        return;
    }

    list.replaceChildren(fragment);
    if (empty) empty.hidden = true;
    setText(panel, '[data-task-detail="comment-count"]', String(comments.length));
    setText(panel, '[data-task-detail="comment-count-side"]', String(comments.length));
}

    function renderTaskDetail() {
          const panel = document.getElementById("task-detail-panel");
    const backdrop = document.getElementById("task-detail-backdrop");

    if (!panel) return;

    const task = state.tasks.find(
        (item) =>
            String(item.id) === String(state.selectedTaskId)
    );

    if (!task) {
        panel.hidden = true;

        if (backdrop) {
            backdrop.hidden = true;
        }

        document.body.classList.remove("has-task-detail");

        return;
    }
    panel.dataset.taskId = String(task.id);
    panel.hidden = false;

    if (backdrop) {
        backdrop.hidden = false;
    }

    document.body.classList.add("has-task-detail");

const group = (db.groups || []).find(
    (item) => String(item.id) === String(task.groupId)
);

const creatorName =
    task.creator?.name ||
    personName(creatorId(task)) ||
    "Noma'lum";

const assigneeName =
    task.assignee?.name ||
    personName(task.assigneeId) ||
    "Biriktirilmagan";


/*
|--------------------------------------------------------------------------
| Source
|--------------------------------------------------------------------------
|
| New Laravel/Blade structure:
|
| sourceType
| sourceText
| confidence
|
| Old JS structure is still supported:
|
| source.kind
| source.text
| source.conf
|
*/

const source = task.source || {};

const sourceType = String(
    task.sourceType ||
    source.kind ||
    "text"
).toLowerCase();

const sourceKindMap = {
    text: "Matn",
    voice: "Ovozli xabar",
    audio: "Ovozli xabar",
    file: "Fayl",
    document: "Hujjat",
    image: "Rasm",
    video: "Video",
};

const sourceKind =
    sourceKindMap[sourceType] || "Xabar";

const sourceText =
    task.sourceText ||
    source.text ||
    task.description ||
    "Manba xabari mavjud emas.";

const confidence = Number(
    task.confidence ??
    source.conf ??
    0
);


/*
|--------------------------------------------------------------------------
| Basic information
|--------------------------------------------------------------------------
*/

setText(
    panel,
    '[data-task-detail="number"]',
    task.number || task.id
);

setText(
    panel,
    '[data-task-detail="group"]',
    group?.title || "Ishlar boshqarmasi"
);

setText(
    panel,
    '[data-task-detail="title"]',
    task.title
);

setText(
    panel,
    '[data-task-detail="title-chain"]',
    task.title
);

setText(
    panel,
    '[data-task-detail="number-chain"]',
    task.number || task.id
);

setText(
    panel,
    '[data-task-detail="description"]',
    task.description || "Tavsif kiritilmagan."
);


/*
|--------------------------------------------------------------------------
| Status
|--------------------------------------------------------------------------
*/

setText(
    panel,
    '[data-task-detail="status"]',
    normalizeStatus(task.status)
);

setText(
    panel,
    '[data-task-detail="status-side"]',
    normalizeStatus(task.status)
);

setData(
    panel,
    '[data-task-detail="status"]',
    "status",
    task.status || ""
);

setData(
    panel,
    '[data-task-detail="status-side"]',
    "status",
    task.status || ""
);


/*
|--------------------------------------------------------------------------
| Priority
|--------------------------------------------------------------------------
*/

setText(
    panel,
    '[data-task-detail="priority"]',
    normalizePriority(task.priority)
);

setText(
    panel,
    '[data-task-detail="priority-side"]',
    normalizePriority(task.priority)
);

setData(
    panel,
    '[data-task-detail="priority"]',
    "priority",
    task.priority || "NORMAL"
);

setData(
    panel,
    '[data-task-detail="priority-side"]',
    "priority",
    task.priority || "NORMAL"
);


/*
|--------------------------------------------------------------------------
| Confidence
|--------------------------------------------------------------------------
*/

setText(
    panel,
    '[data-task-detail="confidence"]',
    confidence
        ? `taxminiy ijrochi ${Math.round(confidence * 100)}%`
        : "aniq biriktirildi"
);

setText(
    panel,
    '[data-task-detail="confidence-value"]',
    confidence
        ? `${Math.round(confidence * 100)}%`
        : "100%"
);


/*
|--------------------------------------------------------------------------
| Source
|--------------------------------------------------------------------------
*/

setText(
    panel,
    '[data-task-detail="source-kind"]',
    sourceKind
);

setText(
    panel,
    '[data-task-detail="source-text"]',
    sourceText
);


/*
|--------------------------------------------------------------------------
| Creator
|--------------------------------------------------------------------------
*/

setText(
    panel,
    '[data-task-detail="creator"]',
    creatorName
);

setText(
    panel,
    '[data-task-detail="creator-side"]',
    creatorName
);

setText(
    panel,
    '[data-task-detail="creator-initials"]',
    initialsFromName(creatorName)
);

setText(
    panel,
    '[data-task-detail="creator-side-initials"]',
    initialsFromName(creatorName)
);


/*
|--------------------------------------------------------------------------
| Assignee
|--------------------------------------------------------------------------
*/

setText(
    panel,
    '[data-task-detail="assignee"]',
    assigneeName
);


/*
|--------------------------------------------------------------------------
| Dates
|--------------------------------------------------------------------------
|
| Laravel database column is:
|
| deadline
|
| Blade sends:
|
| dueAt
| deadline
|
*/

const deadline =
    task.deadline ||
    task.dueAt ||
    null;

const dateTargets = [
    [
        '[data-task-detail="due-date"]',
        deadline,
        formatDate
    ],

    [
        '[data-task-detail="created-at"]',
        task.createdAt,
        formatDateTime
    ],

    [
        '[data-task-detail="created-at-side"]',
        task.createdAt,
        formatDateTime
    ]
];

dateTargets.forEach(
    ([selector, value, formatter]) => {

        const element = panel.querySelector(selector);

        if (!element) return;

        element.textContent = value
            ? formatter(value)
            : "—";

        element.dateTime = value
            ? new Date(value).toISOString()
            : "";

    }
);


/*
|--------------------------------------------------------------------------
| Deadline card
|--------------------------------------------------------------------------
*/

const deadlineCard = panel.querySelector(
    "[data-deadline-card]"
);

if (deadlineCard) {

    /*
    | deadlineState() probably expects task.deadline,
    | so make sure it exists.
    */

    const deadlineTask = {
        ...task,
        deadline,
    };

    deadlineCard.dataset.deadlineState =
        deadlineState(deadlineTask);
}

setText(
    panel,
    '[data-task-detail="deadline-label"]',
    deadline
        ? deadlineText({
            ...task,
            deadline,
        })
        : "Muddat belgilanmagan"
);

setText(
    panel,
    '[data-task-detail="deadline-value"]',
    deadline
        ? formatDateTime(deadline)
        : "SLA hisoblanmaydi"
);


/*
|--------------------------------------------------------------------------
| Task chain
|--------------------------------------------------------------------------
*/

const chainSummary = panel.querySelector(
    "[data-task-chain-summary]"
);

if (chainSummary) {

    chainSummary.textContent =
        task.parentId
            ? `Ota topshiriq: ${task.parentId}`
            : "Ushbu topshiriq mustaqil topshiriq.";

}


/*
|--------------------------------------------------------------------------
| Status line
|--------------------------------------------------------------------------
*/

updateStatusLine(panel, task);


/*
|--------------------------------------------------------------------------
| Related content
|--------------------------------------------------------------------------
*/

renderChecklist(panel, task);

renderComments(panel, task);

renderHistory(panel, task);


/*
|--------------------------------------------------------------------------
| Open modal
|--------------------------------------------------------------------------
*/

panel.hidden = false;

if (backdrop) {
    backdrop.hidden = false;
}

document.body.classList.add(
    "has-task-detail"
);

updateTaskLifecycleButtons(task);
    }

    /*
    |--------------------------------------------------------------------------
    | Toggle lifecycle action buttons based on the task's current status.
    |--------------------------------------------------------------------------
    |
    | Whether a button exists at all is already decided server-side via
    | @can(...) in the Blade template (permission check). This only hides
    | actions that would be rejected by the backend's status-transition
    | rules for the CURRENT status (see TaskService::validateStatusTransition).
    |
    */

    function updateTaskLifecycleButtons(task) {
        const status = String(
            task.status || ""
        ).toLowerCase();

        const startButton = document.querySelector(
            '[data-action="start-task"]'
        );

        if (startButton) {
            const canStart =
                status === "assigned" ||
                status === "accepted";

            startButton.hidden = !canStart;
        }

        const cancelButton = document.querySelector(
            '[data-action="cancel-assignment"]'
        );

        if (cancelButton) {
            const canCancel = !["closed", "cancelled"].includes(
                status
            );

            cancelButton.hidden = !canCancel;
        }
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

function openTaskDetail(taskId) {
    const task = state.tasks.find(
        (item) => String(item.id) === String(taskId)
    );

    if (!task) {
        console.error("Task not found:", taskId);
        console.log("Available tasks:", state.tasks);
        return;
    }

    // Immediately clear activity UI from the previously opened task before
    // rendering the newly selected task. This makes task switching deterministic.
    const existingPanel = document.getElementById("task-detail-panel");
    resetTaskActivityUI(existingPanel, task.id);

    state.selectedTaskId = task.id;

    renderTaskDetail();

    const panel = document.getElementById("task-detail-panel");
    const backdrop = document.getElementById("task-detail-backdrop");

    if (panel) {
        panel.hidden = false;
    }

    if (backdrop) {
        backdrop.hidden = false;
    }

    document.body.classList.add("has-task-detail");
}

    function bindTasks() {
    /*
    |--------------------------------------------------------------------------
    | IMPORTANT
    |--------------------------------------------------------------------------
    |
    | Kanban cards are already rendered by Laravel Blade.
    |
    | DO NOT call renderTasks() here because the old frontend rendering
    | logic can clear the Blade-rendered Kanban columns.
    |
    */

    state.tasks = Array.isArray(window.tasksData)
    ? window.tasksData.map((task) => ({
        ...task,

        /*
        |--------------------------------------------------------------------------
        | Normalize Laravel relations for existing JavaScript
        |--------------------------------------------------------------------------
        */

        assigneeId: task.assignee?.id ?? null,

        assigneeName:
            task.assignee?.name ??
            "Biriktirilmagan",

        creatorId:
            task.creator?.id ??
            null,

        creatorName:
            task.creator?.name ??
            "Noma'lum",

        assignorId:
            task.assignor?.id ??
            null,

        deadline:
            task.dueAt ??
            null,

        labels:
            Array.isArray(task.sprints)
                ? task.sprints.map((sprint) => sprint.name)
                : [],

        comments:
            Array.isArray(task.comments)
                ? task.comments
                : [],

        logs:
            Array.isArray(task.logs)
                ? task.logs
                : [],

        checklist:
            Array.isArray(task.checklist)
                ? task.checklist
                : [],
    }))
    : [];

    const statuses = [
        ...new Set(
            state.tasks
                .map((task) => task.status)
                .filter(Boolean)
        )
    ];

    const priorities = [
        ...new Set(
            state.tasks
                .map((task) => task.priority)
                .filter(Boolean)
        )
    ];

    /*
    |--------------------------------------------------------------------------
    | People
    |--------------------------------------------------------------------------
    */

    const people = state.tasks
        .flatMap((task) => [
            task.assignee?.id
                ? {
                    value: task.assignee.id,
                    label: task.assignee.name,
                }
                : null,

            task.creator?.id
                ? {
                    value: task.creator.id,
                    label: task.creator.name,
                }
                : null,
        ])
        .filter(Boolean)
        .filter(
            (person, index, array) =>
                array.findIndex(
                    (item) => String(item.value) === String(person.value)
                ) === index
        );

    /*
    |--------------------------------------------------------------------------
    | Populate filters
    |--------------------------------------------------------------------------
    */

    populateSelect(
        document.getElementById("task-status-filter"),
        statuses.map((status) => ({
            value: status,
            label: normalizeStatus(status),
        }))
    );

    populateSelect(
        document.getElementById("task-priority-filter"),
        priorities.map((priority) => ({
            value: priority,
            label: normalizePriority(priority),
        }))
    );

    populateSelect(
        document.getElementById("task-assignee-filter"),
        [
            {
                value: "unassigned",
                label: "Biriktirilmagan",
            },

            ...people,
        ]
    );

    populateSelect(
        document.getElementById("task-creator-filter"),
        people
    );

    /*
    |--------------------------------------------------------------------------
    | Filters
    |--------------------------------------------------------------------------
    */

    document.querySelectorAll("[data-filter]").forEach((control) => {

        const updateFilter = () => {

            const key = control.dataset.filter;

            state.filters[key] = control.value;

            state.tablePage = 1;

            /*
            |--------------------------------------------------------------------------
            | IMPORTANT
            |--------------------------------------------------------------------------
            |
            | We do NOT call the old renderTasks() blindly.
            |
            | Instead we filter existing Blade Kanban cards.
            |
            */

            applyKanbanFilters();

            if (state.currentView === "table") {
                renderTable(
                    getQuickFilteredTasks(
                        getFilteredTasks()
                    )
                );
            }
        };

        control.addEventListener("change", updateFilter);

        if (
            control.type === "search"
        ) {
            control.addEventListener(
                "input",
                updateFilter
            );
        }
    });


    /*
    |--------------------------------------------------------------------------
    | Quick filters
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll("[data-quick-filter]")
        .forEach((button) => {

            button.addEventListener(
                "click",
                () => {

                    state.quickFilter =
                        button.dataset.quickFilter;

                    state.tablePage = 1;

                    document
                        .querySelectorAll(
                            "[data-quick-filter]"
                        )
                        .forEach((item) => {

                            item.classList.toggle(
                                "is-active",
                                item === button
                            );
                        });


                    applyKanbanFilters();


                    if (
                        state.currentView === "table"
                    ) {
                        renderTable(
                            getQuickFilteredTasks(
                                getFilteredTasks()
                            )
                        );
                    }
                }
            );
        });


    /*
    |--------------------------------------------------------------------------
    | View switch
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '[data-action="change-view"]'
        )
        .forEach((button) => {

            button.addEventListener(
                "click",
                () => {

                    state.currentView =
                        button.dataset.view;

                    updateView();

                    /*
                    |--------------------------------------------------------------------------
                    | Table is JS rendered
                    |--------------------------------------------------------------------------
                    */

                    if (
                        state.currentView === "table"
                    ) {
                        renderTable(
                            getQuickFilteredTasks(
                                getFilteredTasks()
                            )
                        );
                    }
                }
            );
        });


    /*
    |--------------------------------------------------------------------------
    | Filter panel
    |--------------------------------------------------------------------------
    */

    document
        .querySelector(
            '[data-action="toggle-filters"]'
        )
        ?.addEventListener(
            "click",
            () => {

                const filters =
                    document.getElementById(
                        "tasks-filters"
                    );

                if (filters) {
                    filters.hidden =
                        !filters.hidden;
                }
            }
        );


    /*
    |--------------------------------------------------------------------------
    | Clear filters
    |--------------------------------------------------------------------------
    */

    document
        .querySelector(
            '[data-action="clear-filters"]'
        )
        ?.addEventListener(
            "click",
            () => {

                resetFilters();

                applyKanbanFilters();

                if (
                    state.currentView === "table"
                ) {
                    renderTable(
                        getQuickFilteredTasks(
                            getFilteredTasks()
                        )
                    );
                }
            }
        );


    /*
    |--------------------------------------------------------------------------
    | Create task
    |--------------------------------------------------------------------------
    */

    function openCreateTaskModal() {
        const modal = document.getElementById("create-task-modal");

        if (!modal) {
            return;
        }

        modal.hidden = false;
        document.body.classList.add("has-create-task-modal");
    }

    function closeCreateTaskModal() {
        const modal = document.getElementById("create-task-modal");
        const form = document.getElementById("create-task-form");

        if (!modal) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove("has-create-task-modal");

        if (form) {
            form.reset();
        }

        const errorBox = document.getElementById(
            "create-task-errors"
        );

        if (errorBox) {
            errorBox.hidden = true;
            errorBox.textContent = "";
        }
    }

    document
        .querySelectorAll(
            '[data-action="open-create-task"]'
        )
        .forEach((button) => {
            button.addEventListener(
                "click",
                openCreateTaskModal
            );
        });

    document
        .querySelectorAll(
            '[data-action="close-create-task"]'
        )
        .forEach((button) => {
            button.addEventListener(
                "click",
                closeCreateTaskModal
            );
        });

    document
        .getElementById("create-task-modal")
        ?.addEventListener("click", (event) => {
            if (event.target.id === "create-task-modal") {
                closeCreateTaskModal();
            }
        });

    const createTaskForm = document.getElementById(
        "create-task-form"
    );

    if (createTaskForm) {
        createTaskForm.addEventListener(
            "submit",
            async (event) => {
                event.preventDefault();

                const submitButton = createTaskForm.querySelector(
                    'button[type="submit"]'
                );

                const errorBox = document.getElementById(
                    "create-task-errors"
                );

                const deadlineValue = document.getElementById(
                    "create-task-deadline"
                )?.value;

                const payload = {
                    title: document
                        .getElementById("create-task-title")
                        ?.value?.trim(),

                    description: document
                        .getElementById("create-task-description")
                        ?.value?.trim() || null,

                    assignment_type: "direct",

                    assignee_id:
                        Number(
                            document.getElementById(
                                "create-task-assignee"
                            )?.value
                        ) || null,

                    priority: document.getElementById(
                        "create-task-priority"
                    )?.value || "normal",

                    deadline: deadlineValue || null,
                };

                if (errorBox) {
                    errorBox.hidden = true;
                    errorBox.textContent = "";
                }

                if (submitButton) {
                    submitButton.disabled = true;
                }

                try {
                    await apiRequest("/tasks", {
                        method: "POST",
                        body: payload,
                    });

                    showToast(
                        "Topshiriq yaratildi.",
                        "success"
                    );

                    window.location.reload();
                } catch (error) {
                    if (errorBox) {
                        errorBox.hidden = false;
                        errorBox.textContent = error.message;
                    } else {
                        showToast(error.message, "error");
                    }
                } finally {
                    if (submitButton) {
                        submitButton.disabled = false;
                    }
                }
            }
        );
    }


    /*
    |--------------------------------------------------------------------------
    | Alert task
    |--------------------------------------------------------------------------
    */

    document
        .querySelector(
            '[data-action="focus-alert-task"]'
        )
        ?.addEventListener(
            "click",
            () => {

                const task =
                    state.tasks.find(
                        (item) =>
                            item.status === "PAUSED"
                    ) ||
                    state.tasks[0];

                if (!task) {
                    return;
                }

                state.selectedTaskId =
                    String(task.id);

                renderTaskDetail();
            }
        );


/*
|--------------------------------------------------------------------------
| TASK CARD CLICK
|--------------------------------------------------------------------------
*/

document.addEventListener("click", function (event) {

    const card = event.target.closest(
        ".task-card[data-task-id]"
    );

    if (!card) {
        return;
    }

    if (
        event.target.closest(
            ".task-card__menu"
        )
    ) {
        return;
    }

    const taskId = card.getAttribute(
        "data-task-id"
    );

    console.log(
        "Opening task:",
        taskId
    );

    openTaskDetail(taskId);
});


document.addEventListener("keydown", function (event) {

    if (
        event.key !== "Enter" &&
        event.key !== " "
    ) {
        return;
    }

    const card = event.target.closest(
        ".task-card[data-task-id]"
    );

    if (!card) {
        return;
    }

    event.preventDefault();

    openTaskDetail(
        card.getAttribute(
            "data-task-id"
        )
    );
});

    /*
    |--------------------------------------------------------------------------
    | Close task detail
    |--------------------------------------------------------------------------
    */

    document
    .querySelectorAll('[data-action="close-task"]')
    .forEach((button) => {

        button.addEventListener("click", () => {

            state.selectedTaskId = null;

            const panel = document.getElementById(
                "task-detail-panel"
            );

            const backdrop = document.getElementById(
                "task-detail-backdrop"
            );

            if (panel) {
                panel.hidden = true;
            }

            if (backdrop) {
                backdrop.hidden = true;
            }

            document.body.classList.remove(
                "has-task-detail"
            );
        });
    });


    /*
    |--------------------------------------------------------------------------
    | Detail tabs
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            '[data-action="detail-tab"]'
        )
        .forEach((button) => {

            button.addEventListener(
                "click",
                () => {

                    updateDetailTab(
                        button.dataset.detailTab
                    );
                }
            );
        });


    /*
    |--------------------------------------------------------------------------
    | Table pagination
    |--------------------------------------------------------------------------
    */

    document
        .querySelector(
            '[data-action="table-prev"]'
        )
        ?.addEventListener(
            "click",
            () => {

                state.tablePage =
                    Math.max(
                        1,
                        state.tablePage - 1
                    );

                renderTable(
                    getQuickFilteredTasks(
                        getFilteredTasks()
                    )
                );
            }
        );


    document
        .querySelector(
            '[data-action="table-next"]'
        )
        ?.addEventListener(
            "click",
            () => {

                const total =
                    getQuickFilteredTasks(
                        getFilteredTasks()
                    ).length;

                const totalPages =
                    Math.max(
                        1,
                        Math.ceil(
                            total /
                            state.tablePageSize
                        )
                    );

                state.tablePage =
                    Math.min(
                        totalPages,
                        state.tablePage + 1
                    );

                renderTable(
                    getQuickFilteredTasks(
                        getFilteredTasks()
                    )
                );
            }
        );


    document.addEventListener(
        "click",
        (event) => {

            const button =
                event.target.closest(
                    '[data-action="table-page"]'
                );

            if (!button) {
                return;
            }

            state.tablePage =
                Number(
                    button.dataset.page
                ) || 1;

            renderTable(
                getQuickFilteredTasks(
                    getFilteredTasks()
                )
            );
        }
    );


    /*
    |--------------------------------------------------------------------------
    | Comments
    |--------------------------------------------------------------------------
    */

    const commentForm = document.querySelector(
        '[data-action="comment-form"]'
    );

    if (commentForm) {
        commentForm.addEventListener("submit", async (event) => {
            event.preventDefault();

            // Always use CURRENTLY opened task
            const taskId = state.selectedTaskId;

            if (!taskId) {
                return;
            }

            const textarea = event.currentTarget.querySelector(
                "textarea"
            );

            const body = textarea?.value?.trim();

            if (!body) {
                return;
            }

            // Find the currently selected task again
            const task = state.tasks.find(
                (item) => String(item.id) === String(taskId)
            );

            if (!task) {
                return;
            }

            const submitButton = commentForm.querySelector(
                'button[type="submit"]'
            );

            if (submitButton) {
                submitButton.disabled = true;
            }

            try {
                const response = await apiRequest(
                    `/tasks/${taskId}/comments`,
                    {
                        method: "POST",
                        body: { body },
                    }
                );

                if (!Array.isArray(task.comments)) {
                    task.comments = [];
                }

                task.comments.push({
                    id: response.data.id,
                    body: response.data.body,
                    createdAt: response.data.created_at,

                    staff: {
                        id: response.data.staff?.id ?? null,
                        name:
                            response.data.staff?.full_name ||
                            "Siz",
                    },
                });

                textarea.value = "";

                const panel = document.getElementById(
                    "task-detail-panel"
                );

                if (panel) {
                    renderComments(panel, task);
                }
            } catch (error) {
                showToast(error.message, "error");
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        });
    }


    /*
    |--------------------------------------------------------------------------
    | Task lifecycle actions: start / cancel / archive
    |--------------------------------------------------------------------------
    */

    async function performTaskStatusChange(status, successMessage) {
        const taskId = state.selectedTaskId;

        if (!taskId) {
            return;
        }

        try {
            await apiRequest(`/tasks/${taskId}/status`, {
                method: "POST",
                body: { status },
            });

            showToast(successMessage, "success");
            window.location.reload();
        } catch (error) {
            showToast(error.message, "error");
        }
    }

    document
        .querySelector('[data-action="start-task"]')
        ?.addEventListener("click", (event) => {
            const button = event.currentTarget;
            button.disabled = true;

            performTaskStatusChange(
                "in_progress",
                "Ish boshlandi."
            ).finally(() => {
                button.disabled = false;
            });
        });

    document
        .querySelector('[data-action="cancel-assignment"]')
        ?.addEventListener("click", (event) => {
            if (
                !window.confirm(
                    "Ushbu topshiriqni bekor qilmoqchimisiz?"
                )
            ) {
                return;
            }

            const button = event.currentTarget;
            button.disabled = true;

            performTaskStatusChange(
                "cancelled",
                "Topshiriq bekor qilindi."
            ).finally(() => {
                button.disabled = false;
            });
        });

    document
        .querySelector('[data-action="archive-task"]')
        ?.addEventListener("click", async (event) => {
            if (
                !window.confirm(
                    "Ushbu topshiriqni arxivlamoqchimisiz? Bu amalni keyin bekor qilib bo‘lmaydi."
                )
            ) {
                return;
            }

            const taskId = state.selectedTaskId;

            if (!taskId) {
                return;
            }

            const button = event.currentTarget;
            button.disabled = true;

            try {
                await apiRequest(`/tasks/${taskId}`, {
                    method: "DELETE",
                });

                showToast(
                    "Topshiriq arxivlandi.",
                    "success"
                );

                window.location.reload();
            } catch (error) {
                showToast(error.message, "error");
                button.disabled = false;
            }
        });


    /*
    |--------------------------------------------------------------------------
    | INITIALIZE
    |--------------------------------------------------------------------------
    */

    updateView();

    /*
    |--------------------------------------------------------------------------
    | DO NOT CALL renderTasks()
    |--------------------------------------------------------------------------
    |
    | Your Blade already rendered:
    |
    | - NEW
    | - ASSIGNED
    | - IN_PROGRESS
    | - SUBMITTED
    | - ACCEPTED
    | - APPROVED
    | - CLOSED
    |
    | Calling old renderTasks() may remove all cards.
    |
    */

    applyKanbanFilters();
}

function applyKanbanFilters() {

    const filteredTasks =
        getQuickFilteredTasks(
            getFilteredTasks()
        );

    const visibleTaskIds =
        new Set(
            filteredTasks.map(
                (task) =>
                    String(task.id)
            )
        );


    /*
    |--------------------------------------------------------------------------
    | Show / hide existing Blade cards
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            "#kanban-view [data-task-id]"
        )
        .forEach((card) => {

            const taskId =
                String(
                    card.dataset.taskId
                );

            card.hidden =
                !visibleTaskIds.has(
                    taskId
                );
        });


    /*
    |--------------------------------------------------------------------------
    | Update column counts
    |--------------------------------------------------------------------------
    */

    document
        .querySelectorAll(
            "#kanban-view .kanban-column"
        )
        .forEach((column) => {

            const status =
                column.dataset.status;

            const visibleCards =
                [...column.querySelectorAll(
                    "[data-task-id]"
                )]
                .filter(
                    (card) =>
                        !card.hidden
                );

            const count =
                column.querySelector(
                    `[data-column-count="${status}"]`
                );

            if (count) {
                count.textContent =
                    visibleCards.length;
            }


            /*
            |--------------------------------------------------------------------------
            | Empty state
            |--------------------------------------------------------------------------
            */

            let empty =
                column.querySelector(
                    ".kanban-column__empty"
                );

            if (
                visibleCards.length === 0
            ) {

                if (!empty) {

                    empty =
                        document.createElement(
                            "p"
                        );

                    empty.className =
                        "kanban-column__empty";

                    empty.textContent =
                        "Topshiriqlar yo‘q";

                    column
                        .querySelector(
                            ".kanban-column__tasks"
                        )
                        ?.appendChild(
                            empty
                        );
                }

                empty.hidden = false;

            } else if (empty) {

                empty.hidden = true;
            }
        });


    /*
    |--------------------------------------------------------------------------
    | Global empty state
    |--------------------------------------------------------------------------
    */

    const emptyState =
        document.getElementById(
            "tasks-empty-state"
        );

    if (emptyState) {

        emptyState.hidden =
            filteredTasks.length > 0;
    }
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

    // function renderPeoplePagination(total) {
    //     const pagination = document.getElementById("people-pagination");
    //     const summary = document.getElementById("people-pagination-summary");
    //     if (!pagination) return;
    //     clearNode(pagination);
    //     const totalPages = Math.max(1, Math.ceil(total / state.peoplePageSize));
    //     state.peoplePage = Math.min(state.peoplePage, totalPages);
    //     const start = total ? ((state.peoplePage - 1) * state.peoplePageSize) + 1 : 0;
    //     const end = Math.min(state.peoplePage * state.peoplePageSize, total);
    //     if (summary) summary.textContent = total ? `${start}–${end} / ${total} ta xodim` : "0 ta xodim";

    //     for (let pageNumber = 1; pageNumber <= totalPages; pageNumber += 1) {
    //         const fragment = cloneTemplate("pagination-button-template");
    //         if (!fragment) continue;
    //         const button = fragment.querySelector('[data-action="change-people-page"]');
    //         if (button) {
    //             button.dataset.page = String(pageNumber);
    //             button.textContent = String(pageNumber);
    //             button.classList.toggle("is-active", pageNumber === state.peoplePage);
    //         }
    //         pagination.append(fragment);
    //     }
    // }

    // function renderPeople() {
    //     const list = document.getElementById("people-list");
    //     if (!list) return;
    //     const people = getFilteredPeople();
    //     const totalPages = Math.max(1, Math.ceil(people.length / state.peoplePageSize));
    //     state.peoplePage = Math.min(state.peoplePage, totalPages);
    //     const offset = (state.peoplePage - 1) * state.peoplePageSize;
    //     const pagePeople = people.slice(offset, offset + state.peoplePageSize);

    //     clearNode(list);
    //     pagePeople.forEach((person) => {
    //         const fragment = cloneTemplate("person-row-template");
    //         if (!fragment) return;
    //         const row = fragment.querySelector(".person-row");
    //         if (row) row.dataset.personId = person.id;
    //         setText(fragment, '[data-field="initials"]', initials(person.fullName));
    //         setText(fragment, '[data-field="name"]', person.fullName);
    //         setText(fragment, '[data-field="meta"]', `${person.username ? `@${person.username}` : "username yo‘q"} · ${person.post || "Xodim"}`);
    //         setText(fragment, '[data-field="telegram-id"]', person.tgId ? String(person.tgId).replace(/(\d{3})(?=\d)/g, "$1 ") : "aniqlanmagan");

    //         const aliases = fragment.querySelector('[data-field="aliases"]');
    //         if (aliases) {
    //             clearNode(aliases);
    //             (person.aliases || []).slice(0, 4).forEach((alias) => appendTemplateValue(aliases, "person-alias-template", '[data-field="alias"]', alias));
    //         }
    //         const groups = fragment.querySelector('[data-field="groups"]');
    //         if (groups) {
    //             clearNode(groups);
    //             getPersonGroups(person).forEach((group) => appendTemplateValue(groups, "person-group-template", '[data-field="group"]', group));
    //         }
    //         const status = fragment.querySelector('[data-field="status"]');
    //         if (status) {
    //             status.textContent = person.status === "ACTIVE" ? "Faol" : "Tasdiq kutilmoqda";
    //             status.classList.toggle("is-pending", person.status !== "ACTIVE");
    //         }
    //         list.append(fragment);
    //     });

    //     const summary = document.getElementById("people-summary");
    //     const empty = document.getElementById("people-empty");
    //     if (summary) summary.textContent = `${people.length} ta shaxs · ${people.filter((person) => person.status === "ACTIVE").length} faol · ${(db.persons || []).filter((person) => person.mergedInto).length} ehtimoliy dublikat`;
    //     if (empty) empty.hidden = people.length > 0;
    //     renderPeoplePagination(people.length);
    // }

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
            const actionElement = event.target.closest("[data-action]");
            const row = event.target.closest(".person-row");

            if (!actionElement || !row) {
                return;
            }

            const action = actionElement.dataset.action;

            /*
            |--------------------------------------------------------------------------
            | Open profile
            |--------------------------------------------------------------------------
            */

            if (action === "open-person-profile") {
                openPersonModal(row.dataset.personId);

                return;
            }

            /*
            |--------------------------------------------------------------------------
            | Edit person
            |--------------------------------------------------------------------------
            */

            if (action === "edit-person") {
                openPersonEditModal(actionElement);

                return;
            }
        });
        document.querySelectorAll('[data-action="close-person-modal"]').forEach((button) => button.addEventListener("click", closePersonModal));
        document.getElementById("person-modal")?.addEventListener("click", (event) => {
            if (event.target.id === "person-modal") closePersonModal();
        });

        document.addEventListener("keydown", (event) => {
            if (event.key !== "Escape") {
                return;
            }

            closePersonModal();
            closePersonEditModal();
        });

        /*
        |--------------------------------------------------------------------------
        | Edit modal close buttons
        |--------------------------------------------------------------------------
        */

        document
            .querySelectorAll('[data-action="close-person-edit-modal"]')
            .forEach((button) => {
                button.addEventListener(
                    "click",
                    closePersonEditModal
                );
            });


        /*
        |--------------------------------------------------------------------------
        | Click outside edit modal
        |--------------------------------------------------------------------------
        */

        document
            .getElementById("person-edit-modal")
            ?.addEventListener("click", (event) => {
                if (event.target.id === "person-edit-modal") {
                    closePersonEditModal();
                }
            });
        renderPeople();
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

    function bindLogin() {
        document.getElementById("login-form")?.addEventListener("submit", (event) => {
            window.location.href = "dashboard";
        });
    }


    function updateNotificationCount(unreadCount) {
        document.querySelectorAll(".notification-count").forEach((element) => {
            element.textContent = String(unreadCount);
            element.hidden = unreadCount === 0;
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

        trigger.addEventListener("click", (event) => {
            event.stopPropagation();
            const nextHidden = !card.hidden;
            card.hidden = nextHidden;
            trigger.setAttribute("aria-expanded", String(!nextHidden));
        });

        card.addEventListener("click", async (event) => {
            const dismiss = event.target.closest('[data-action="dismiss-notification"]');
            const markAllButton = event.target.closest('[data-action="mark-notifications-read"]');

            if (markAllButton) {
                markAllButton.disabled = true;

                try {
                    const response = await apiRequest(
                        "/notifications/read-all",
                        { method: "POST" }
                    );

                    card.querySelectorAll(
                        '[data-notification-read="false"]'
                    ).forEach((item) => {
                        item.dataset.notificationRead = "true";
                        item.querySelector(
                            '[data-action="dismiss-notification"]'
                        )?.remove();
                    });

                    markAllButton.remove();
                    updateNotificationCount(response?.unread_count ?? 0);
                } catch (error) {
                    showToast(error.message, "error");
                    markAllButton.disabled = false;
                }

                return;
            }

            if (!dismiss) {
                return;
            }

            const item = dismiss.closest("[data-notification-id]");

            if (!item) {
                return;
            }

            dismiss.disabled = true;

            try {
                const response = await apiRequest(
                    `/notifications/${item.dataset.notificationId}/read`,
                    { method: "POST" }
                );

                item.dataset.notificationRead = "true";
                dismiss.remove();
                updateNotificationCount(response?.unread_count ?? 0);
            } catch (error) {
                showToast(error.message, "error");
                dismiss.disabled = false;
            }
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
    }


    // Global delegated task-row opener. Rows are dynamically re-rendered, so
    // delegation keeps the drawer working after filtering and table updates.
    document.addEventListener("click", (event) => {
        const row = event.target.closest(
            '[data-page="tasks"] [data-task-id], [data-page="tasks"] [data-id]'
        );

        if (!row) return;

        if (event.target.closest("a, button, input, select, textarea, label")) {
            return;
        }

        const taskId = row.dataset.taskId || row.dataset.id;
        if (!taskId) return;

        event.preventDefault();
        openTaskDetail(taskId);
    });

function initialize() {
        initializeTheme();
        state.tasks = Array.isArray(db.tasks) ? db.tasks.slice() : [];
        bindGlobalBehavior();
        bindNotifications();
        if (page === "tasks") bindTasks();
        if (page === "people") bindPeople();
        if (page === "chain") renderChain();
        if (page === "login") bindLogin();
        initializeDashboard();
    }

    document.addEventListener("DOMContentLoaded", initialize);

    function openPersonEditModal(button) {

    const modal = document.getElementById(
        "person-edit-modal"
    );

    const form = document.getElementById(
        "person-edit-form"
    );

    if (!modal || !form || !button) {
        return;
    }

    const data = button.dataset;


    /*
    |--------------------------------------------------------------------------
    | Form action
    |--------------------------------------------------------------------------
    */

    form.action = data.updateUrl || "";


    /*
    |--------------------------------------------------------------------------
    | Hidden ID
    |--------------------------------------------------------------------------
    */

    const idInput = document.getElementById(
        "edit-person-id"
    );

    if (idInput) {
        idInput.value = data.personId || "";
    }


    /*
    |--------------------------------------------------------------------------
    | Basic fields
    |--------------------------------------------------------------------------
    */

    const fullName = document.getElementById(
        "edit-full-name"
    );

    const username = document.getElementById(
        "edit-username"
    );

    const login = document.getElementById(
        "edit-login"
    );

    const lavozim = document.getElementById(
        "edit-lavozim"
    );


    if (fullName) {
        fullName.value = data.fullName || "";
    }

    if (username) {
        username.value = data.username || "";
    }

    if (login) {
        login.value = data.login || "";
    }

    if (lavozim) {
        lavozim.value = data.lavozim || "";
    }


    /*
    |--------------------------------------------------------------------------
    | Status
    |--------------------------------------------------------------------------
    */

    const status = document.getElementById(
        "edit-status"
    );

    if (status) {
        status.value = data.status || "active";
    }


    /*
    |--------------------------------------------------------------------------
    | Telegram
    |--------------------------------------------------------------------------
    */

    const telegramChatId = document.getElementById(
        "edit-telegram-chat-id"
    );

    if (telegramChatId) {
        telegramChatId.value =
            data.telegramChatId || "";
    }


    /*
    |--------------------------------------------------------------------------
    | Group
    |--------------------------------------------------------------------------
    */

    const groupName = document.getElementById(
        "edit-group-name"
    );

    const groupChatId = document.getElementById(
        "edit-group-chat-id"
    );


    if (groupName) {
        groupName.value =
            data.groupName || "";
    }

    if (groupChatId) {
        groupChatId.value =
            data.groupChatId || "";
    }


    /*
    |--------------------------------------------------------------------------
    | Role
    |--------------------------------------------------------------------------
    */

    const role = document.getElementById(
        "edit-role"
    );

    if (role) {
        role.value =
            data.role || "";
    }


    /*
    |--------------------------------------------------------------------------
    | Password
    |--------------------------------------------------------------------------
    */

    const password = document.getElementById(
        "edit-password"
    );

    const passwordConfirmation = document.getElementById(
        "edit-password-confirmation"
    );

    if (password) {
        password.value = "";
    }

    if (passwordConfirmation) {
        passwordConfirmation.value = "";
    }


    /*
    |--------------------------------------------------------------------------
    | Open modal
    |--------------------------------------------------------------------------
    */

    modal.hidden = false;

    document.body.classList.add(
        "has-person-edit-modal"
    );
    }


    function closePersonEditModal() {

        const modal = document.getElementById(
            "person-edit-modal"
        );

        const form = document.getElementById(
            "person-edit-form"
        );

        if (!modal) {
            return;
        }

        modal.hidden = true;

        document.body.classList.remove(
            "has-person-edit-modal"
        );

        if (form) {
            form.reset();
        }
    }

    function renderDashboardDynamics() {

        const chart = document.querySelector(
            '[data-chart="task-dynamics"]'
        );

        if (!chart) {
            return;
        }

        const svg = chart.querySelector("svg");

        if (!svg) {
            return;
        }

        const dynamics =
            window.dashboardData?.dynamics || [];

        if (!Array.isArray(dynamics) || !dynamics.length) {

            svg.innerHTML = `
                <text
                    x="460"
                    y="135"
                    text-anchor="middle"
                >
                    Ma'lumot mavjud emas
                </text>
            `;

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Chart dimensions
        |--------------------------------------------------------------------------
        */

        const width = 920;
        const height = 270;

        const padding = {
            top: 28,
            right: 30,
            bottom: 34,
            left: 50
        };

        const chartWidth =
            width -
            padding.left -
            padding.right;

        const chartHeight =
            height -
            padding.top -
            padding.bottom;


        /*
        |--------------------------------------------------------------------------
        | Maximum value
        |--------------------------------------------------------------------------
        */

        const values = dynamics.flatMap(
            (item) => [
                Number(item.created || 0),
                Number(item.accepted || 0)
            ]
        );

        const rawMax = Math.max(
            ...values,
            1
        );

        const maxValue =
            Math.ceil(rawMax / 2) * 2;


        /*
        |--------------------------------------------------------------------------
        | Coordinate helpers
        |--------------------------------------------------------------------------
        */

        const getX = (index) => {

            if (dynamics.length === 1) {
                return padding.left + chartWidth / 2;
            }

            return (
                padding.left +
                (
                    index /
                    (dynamics.length - 1)
                ) *
                chartWidth
            );
        };


        const getY = (value) => {

            return (
                padding.top +
                chartHeight -
                (
                    value / maxValue
                ) *
                chartHeight
            );
        };


        /*
        |--------------------------------------------------------------------------
        | Build points
        |--------------------------------------------------------------------------
        */

        const createdPoints =
            dynamics
                .map(
                    (item, index) => {

                        return `${getX(index)},${getY(
                            Number(item.created || 0)
                        )}`;

                    }
                )
                .join(" ");


        const acceptedPoints =
            dynamics
                .map(
                    (item, index) => {

                        return `${getX(index)},${getY(
                            Number(item.accepted || 0)
                        )}`;

                    }
                )
                .join(" ");


        /*
        |--------------------------------------------------------------------------
        | Area
        |--------------------------------------------------------------------------
        */

        const firstX = getX(0);

        const lastX =
            getX(dynamics.length - 1);

        const createdAreaPoints =
            dynamics
                .map(
                    (item, index) => {

                        return `${getX(index)},${getY(
                            Number(item.created || 0)
                        )}`;

                    }
                )
                .join(" L");


        const bottomY =
            padding.top +
            chartHeight;


        const areaPath = `

            M ${firstX} ${bottomY}

            L ${createdAreaPoints}

            L ${lastX} ${bottomY}

            Z

        `;


        /*
        |--------------------------------------------------------------------------
        | Grid
        |--------------------------------------------------------------------------
        */

        const gridSteps = 4;

        let gridHtml = "";


        for (
            let index = 0;
            index <= gridSteps;
            index++
        ) {

            const value =
                (
                    maxValue /
                    gridSteps
                ) *
                index;

            const y =
                getY(value);


            gridHtml += `

                <line
                    x1="${padding.left}"
                    x2="${width - padding.right}"
                    y1="${y}"
                    y2="${y}"
                ></line>

                <text
                    x="${padding.left - 12}"
                    y="${y + 5}"
                    text-anchor="end"
                >
                    ${Math.round(value)}
                </text>

            `;
        }


        /*
        |--------------------------------------------------------------------------
        | X labels
        |--------------------------------------------------------------------------
        */

        let labelsHtml = "";


        dynamics.forEach(
            (item, index) => {

                const x =
                    getX(index);


                labelsHtml += `

                    <text
                        x="${x}"
                        y="${height - 8}"
                        text-anchor="middle"
                    >
                        ${item.label || ""}
                    </text>

                `;

            }
        );


        /*
        |--------------------------------------------------------------------------
        | Render SVG
        |--------------------------------------------------------------------------
        */

        svg.innerHTML = `

            <g class="chart-grid">

                ${gridHtml}

            </g>


            <g class="chart-axis-labels">

                ${labelsHtml}

            </g>


            <path
                class="chart-area"
                d="${areaPath}"
            ></path>


            <polyline
                class="chart-line chart-line--opened"
                points="${createdPoints}"
            ></polyline>


            <polyline
                class="chart-line chart-line--accepted"
                points="${acceptedPoints}"
            ></polyline>

        `;
    }

    function renderDashboardStatusDonut() {

        const donut =
            document.getElementById(
                "dashboard-status-donut"
            );

        if (!donut) {
            return;
        }


        const statuses =
            window.dashboardData
                ?.statusDistribution || {};


        const values = Object.values(
            statuses
        ).map(Number);


        const total =
            values.reduce(
                (sum, value) =>
                    sum + value,
                0
            );


        if (!total) {

            donut.style.background =
                "conic-gradient(#e5e7eb 0deg 360deg)";

            return;
        }


        /*
        |--------------------------------------------------------------------------
        | Status segments
        |--------------------------------------------------------------------------
        */

        const segments = [

            {
                key: "created",
                color: "var(--status-new, #6b7280)"
            },

            {
                key: "assigned",
                color: "var(--status-assigned, #3b82f6)"
            },

            {
                key: "in_progress",
                color: "var(--status-progress, #f59e0b)"
            },

            {
                key: "awaiting_acceptance",
                color: "var(--status-review, #8b5cf6)"
            },

            {
                key: "accepted",
                color: "var(--status-accepted, #10b981)"
            },

            {
                key: "closed",
                color: "var(--status-closed, #374151)"
            },

            {
                key: "returned",
                color: "var(--status-returned, #ef4444)"
            },

            {
                key: "cancelled",
                color: "var(--status-danger, #dc2626)"
            }

        ];


        let current = 0;

        const gradients = [];


        segments.forEach(
            (segment) => {

                const count =
                    Number(
                        statuses[
                            segment.key
                        ] || 0
                    );


                if (!count) {
                    return;
                }


                const percentage =
                    (
                        count /
                        total
                    ) *
                    100;


                const start =
                    current;


                const end =
                    current +
                    percentage;


                gradients.push(
                    `${segment.color}
                    ${start}%
                    ${end}%`
                );


                current = end;

            }
        );


        donut.style.background =
            `conic-gradient(
                ${gradients.join(", ")}
            )`;
    }

    function initializeDashboard() {

        renderDashboardDynamics();

        renderDashboardStatusDonut();

    }
})();
