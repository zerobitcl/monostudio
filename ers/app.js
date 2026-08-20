"use strict";

/* ============================================================
   MONO STUDIO OS — Base de operaciones
   Store (persistencia) → Módulos de dominio → AppController.

   La pantalla "Hoy" es una agenda única: dinero, solicitudes,
   tareas y señales SEO comparten el mismo modelo de item para
   que la priorización viva en un solo lugar (Agenda.build).
   ============================================================ */

/** Horas tras las que una solicitud pasa a urgente. */
const OVERDUE_HOURS = 48;

/** Ventana del gráfico de flujo de cobros (días). */
const CHART_WINDOW_DAYS = 30;

/** Días para marcar un cobro como urgente. */
const URGENT_DAYS = 3;

/** Días de anticipación con que las tareas futuras entran a la agenda. */
const TASK_LOOKAHEAD_DAYS = 7;

const TIMER_TICK_MS = 60 * 1000;

/* ------------------------------------------------------------
   Persistencia
   ------------------------------------------------------------ */
class Store {
  static KEYS = {
    view: "monoStudio.view",
    expanded: "monoStudio.expanded",
    module: "monoStudio.module",
    seoHost: "monoStudio.seoHost",
    seoCache: "monoStudio.seoCache",
  };

  static API = "./api/store.php";
  static cache = { clients: [], requests: [], tasks: [] };

  static async init() {
    const res = await fetch(Store.API, { headers: { Accept: "application/json" } });
    if (!res.ok) throw new Error(`No se pudo conectar al servidor (${res.status})`);

    const data = await res.json();
    Store.cache = {
      clients: Array.isArray(data.clients) ? data.clients : [],
      requests: Array.isArray(data.requests) ? data.requests : [],
      tasks: Array.isArray(data.tasks) ? data.tasks : [],
    };
    return Store.cache;
  }

  static async save(clients, requests, tasks) {
    Store.cache = { clients, requests, tasks };
    const res = await fetch(Store.API, {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(Store.cache),
    });
    if (!res.ok) {
      const err = await res.json().catch(() => ({}));
      throw new Error(err.error || `Error al guardar (${res.status})`);
    }
  }
}

/* ------------------------------------------------------------
   Finanzas
   ------------------------------------------------------------ */
class FinanceModule {
  static #clp = new Intl.NumberFormat("es-CL", {
    style: "currency",
    currency: "CLP",
    maximumFractionDigits: 0,
  });

  static formatCLP(amount) {
    return FinanceModule.#clp.format(Math.round(amount || 0));
  }

  /**
   * MRR = mensuales + (anuales ÷ 12) · ARR = anuales + (mensuales × 12).
   * Los clientes en desarrollo no facturan: cuentan como pipeline.
   */
  static computeMetrics(clients) {
    const totals = clients.reduce(
      (acc, c) => {
        const value = Number(c.valueCLP) || 0;
        if (c.inDevelopment) {
          acc.devCount += 1;
          acc.devPipeline += c.planType === "anual" ? value / 12 : value;
          return acc;
        }
        if (c.planType === "anual") acc.annual += value;
        else acc.monthly += value;
        return acc;
      },
      { annual: 0, monthly: 0, devCount: 0, devPipeline: 0 }
    );

    return {
      portfolio: totals.annual + totals.monthly,
      mrr: totals.monthly + totals.annual / 12,
      arr: totals.annual + totals.monthly * 12,
      devCount: totals.devCount,
      devPipeline: totals.devPipeline,
      activeCount: clients.length - totals.devCount,
    };
  }
}

/* ------------------------------------------------------------
   Fechas y cobros
   ------------------------------------------------------------ */
class BillingModule {
  static #short = new Intl.DateTimeFormat("es-CL", { day: "numeric", month: "short" });
  static #long = new Intl.DateTimeFormat("es-CL", { weekday: "short", day: "numeric", month: "long" });

  static toISODate(date) {
    const y = date.getFullYear();
    const m = String(date.getMonth() + 1).padStart(2, "0");
    const d = String(date.getDate()).padStart(2, "0");
    return `${y}-${m}-${d}`;
  }

  static startOfDay(date = new Date()) {
    const d = new Date(date);
    d.setHours(0, 0, 0, 0);
    return d;
  }

  static parseDate(iso) {
    const [y, m, d] = String(iso).split("-").map(Number);
    return new Date(y, (m || 1) - 1, d || 1);
  }

  static daysUntil(isoDate) {
    const today = BillingModule.startOfDay();
    const target = BillingModule.startOfDay(BillingModule.parseDate(isoDate));
    return Math.round((target - today) / 86400000);
  }

  static urgency(days) {
    if (days < 0) return "overdue";
    if (days <= URGENT_DAYS) return "soon";
    return "normal";
  }

  static relativeLabel(days) {
    if (days < 0) return `Vencido hace ${Math.abs(days)} día${Math.abs(days) === 1 ? "" : "s"}`;
    if (days === 0) return "Hoy";
    if (days === 1) return "Mañana";
    return `En ${days} días`;
  }

  static formatShort(iso) {
    return BillingModule.#short.format(BillingModule.parseDate(iso));
  }

  static formatLong(iso) {
    return BillingModule.#long.format(BillingModule.parseDate(iso));
  }

  static migrateClient(client) {
    if (!client.nextBillingDate) {
      const d = new Date();
      d.setMonth(d.getMonth() + 1, 1);
      client.nextBillingDate = BillingModule.toISODate(d);
    }
    if (!Array.isArray(client.notes)) client.notes = [];
    if (typeof client.siteUrl !== "string") client.siteUrl = "";
    return client;
  }

  static sortByBilling(clients) {
    return [...clients].sort(
      (a, b) =>
        BillingModule.parseDate(a.nextBillingDate).getTime() -
        BillingModule.parseDate(b.nextBillingDate).getTime()
    );
  }

  static buildCashFlowSeries(clients, days = CHART_WINDOW_DAYS) {
    const today = BillingModule.startOfDay();
    const buckets = [];
    for (let i = 0; i < days; i++) {
      const d = new Date(today);
      d.setDate(d.getDate() + i);
      buckets.push({ date: BillingModule.toISODate(d), total: 0, clients: [], isToday: i === 0 });
    }
    const map = new Map(buckets.map((b) => [b.date, b]));
    clients.forEach((c) => {
      const bucket = map.get(c.nextBillingDate);
      if (bucket) {
        bucket.total += Number(c.valueCLP) || 0;
        bucket.clients.push(c);
      }
    });
    return buckets;
  }

  static advanceBillingDate(client) {
    const d = BillingModule.parseDate(client.nextBillingDate);
    if (client.planType === "anual") d.setFullYear(d.getFullYear() + 1);
    else d.setMonth(d.getMonth() + 1);
    return BillingModule.toISODate(d);
  }
}

/* ------------------------------------------------------------
   Contacto
   ------------------------------------------------------------ */
class ContactModule {
  /** Normaliza a E.164 asumiendo Chile: "9 1234 5678" → "56912345678". */
  static normalizePhone(raw) {
    if (!raw) return null;
    const digits = String(raw).replace(/\D/g, "");
    if (!digits) return null;
    if (digits.startsWith("56") && digits.length >= 11) return digits;
    if (digits.length === 9 && digits.startsWith("9")) return `56${digits}`;
    if (digits.length === 8) return `569${digits}`;
    return digits;
  }

  static waLink(phone, clientName) {
    const normalized = ContactModule.normalizePhone(phone);
    if (!normalized) return null;
    const text = encodeURIComponent(`Hola ${clientName}, te escribo de Mono Studio.`);
    return `https://wa.me/${normalized}?text=${text}`;
  }

  static telLink(phone) {
    const normalized = ContactModule.normalizePhone(phone);
    return normalized ? `tel:+${normalized}` : null;
  }
}

/* ------------------------------------------------------------
   Search Console (proxy PHP)
   ------------------------------------------------------------ */
class GscModule {
  static API = "./api/gsc.php";

  static #url(action, extra = "") {
    const qs = extra ? `${extra}&` : "";
    return `${GscModule.API}?action=${action}&${qs}_=${Date.now()}`;
  }

  static async #get(action, extra = "") {
    const res = await fetch(GscModule.#url(action, extra), {
      cache: "no-store",
      headers: { Accept: "application/json" },
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || `Search Console respondió ${res.status}`);
    return data;
  }

  static sites() {
    return GscModule.#get("sites");
  }

  static site(host, fresh = false) {
    return GscModule.#get("site", `host=${encodeURIComponent(host)}${fresh ? "&fresh=1" : ""}`);
  }

  static async saveConfig(payload) {
    const res = await fetch(GscModule.#url("config"), {
      method: "POST",
      cache: "no-store",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify(payload),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok) throw new Error(data.error || "No se pudo guardar");
    return data;
  }

  static hostOf(url) {
    try {
      return new URL(String(url)).hostname.replace(/^www\./i, "").toLowerCase();
    } catch {
      return "";
    }
  }

  static parsePages(raw) {
    return String(raw || "")
      .split("\n")
      .map((line) => line.trim())
      .filter(Boolean)
      .slice(0, 40)
      .map((line) => {
        const [url, ...rest] = line.split("|");
        return { url: url.trim(), label: rest.join("|").trim() };
      })
      .filter((p) => /^https?:\/\//i.test(p.url));
  }

  static serializePages(pages) {
    return (pages || [])
      .filter((p) => p?.url)
      .map((p) => (p.label ? `${p.url} | ${p.label}` : p.url))
      .join("\n");
  }

  static fmt(n) {
    return new Intl.NumberFormat("es-CL").format(Math.round(n || 0));
  }

  static pct(n) {
    return `${((n || 0) * 100).toFixed(1)}%`;
  }

  static pos(n) {
    return (n || 0).toFixed(1);
  }

  static delta(value, { invert = false, percent = false, position = false } = {}) {
    if (!value) return { text: "sin cambio", cls: "" };
    const up = invert ? value < 0 : value > 0;
    const sign = value > 0 ? "+" : "";
    let text;
    if (percent) text = `${sign}${(value * 100).toFixed(1)} pp`;
    else if (position) text = `${value > 0 ? "↑" : "↓"} ${Math.abs(value).toFixed(1)}`;
    else text = `${sign}${GscModule.fmt(value)}`;
    return { text, cls: up ? "is-up" : "is-down" };
  }
}

/**
 * Caché de resultados SEO por host. Vive en sessionStorage para que cambiar
 * de pestaña sea instantáneo sin volver a golpear la API de Google.
 */
class SeoStore {
  static data = new Map();

  static load() {
    try {
      const raw = sessionStorage.getItem(Store.KEYS.seoCache);
      if (!raw) return;
      Object.entries(JSON.parse(raw)).forEach(([host, value]) => SeoStore.data.set(host, value));
    } catch {
      /* caché corrupta: se reconstruye sola */
    }
  }

  static persist() {
    try {
      sessionStorage.setItem(
        Store.KEYS.seoCache,
        JSON.stringify(Object.fromEntries(SeoStore.data))
      );
    } catch {
      /* cuota llena: seguimos solo en memoria */
    }
  }

  static get(host) {
    return SeoStore.data.get(host) || null;
  }

  static set(host, value) {
    SeoStore.data.set(host, value);
    SeoStore.persist();
  }
}

/* ------------------------------------------------------------
   Agenda: un solo modelo de item para todo lo pendiente
   ------------------------------------------------------------ */
class Agenda {
  static GROUPS = { money: "Dinero", seo: "SEO", task: "Tareas" };

  static build({ clients, requests, tasks }) {
    return [
      ...Agenda.#fromBilling(clients),
      ...Agenda.#fromRequests(requests, clients),
      ...Agenda.#fromTasks(tasks, clients),
      ...Agenda.#fromSeo(clients),
    ].sort((a, b) => b.severity - a.severity || a.title.localeCompare(b.title));
  }

  static #fromBilling(clients) {
    const items = [];
    clients.forEach((client) => {
      const days = BillingModule.daysUntil(client.nextBillingDate);

      if (client.inDevelopment) {
        if (days > 0) return;
        items.push({
          id: `dev:${client.id}`,
          group: "money",
          severity: 2,
          title: `Lanzar ${client.name}`,
          detail: `Fecha estimada ${BillingModule.formatLong(client.nextBillingDate)}. Al activarlo suma ${FinanceModule.formatCLP(client.valueCLP)} al plan ${client.planType}.`,
          clientId: client.id,
          clientName: client.name,
          actions: [
            { label: "Activar", act: "activate", value: client.id, primary: true },
            ...Agenda.#contactActions(client),
          ],
        });
        return;
      }

      if (days > URGENT_DAYS) return;
      items.push({
        id: `bill:${client.id}`,
        group: "money",
        severity: days < 0 ? 3 : 2,
        title: `Cobrar a ${client.name}`,
        detail: `${FinanceModule.formatCLP(client.valueCLP)} · ${BillingModule.relativeLabel(days)} (${BillingModule.formatLong(client.nextBillingDate)})`,
        clientId: client.id,
        clientName: client.name,
        actions: [
          { label: "Cobrado", act: "paid", value: client.id, primary: true },
          ...Agenda.#contactActions(client),
        ],
      });
    });
    return items;
  }

  static #contactActions(client) {
    const wa = ContactModule.waLink(client.phone, client.name);
    return wa ? [{ label: "WhatsApp", act: "link", value: wa }] : [];
  }

  static #fromRequests(requests, clients) {
    return requests
      .filter((r) => r.status === "activa")
      .map((req) => {
        const client = clients.find((c) => c.id === req.clientId);
        const hours = Math.floor((Date.now() - Number(req.createdAt)) / 3600000);
        const overdue = hours >= OVERDUE_HOURS;
        return {
          id: `req:${req.id}`,
          group: "task",
          severity: overdue ? 3 : 1,
          title: req.description,
          detail: overdue
            ? `Solicitud abierta hace ${Math.floor(hours / 24)} día${Math.floor(hours / 24) === 1 ? "" : "s"}`
            : `Solicitud abierta hace ${hours}h`,
          clientId: client?.id || "",
          clientName: client?.name || "Cliente eliminado",
          actions: [{ label: "Completar", act: "request-done", value: req.id, primary: true }],
        };
      });
  }

  static #fromTasks(tasks, clients) {
    const items = [];
    tasks
      .filter((t) => !t.doneAt)
      .forEach((task) => {
        const client = clients.find((c) => c.id === task.clientId);
        let severity = 2;
        let detail = "Tarea sin fecha";

        if (task.dueDate) {
          const days = BillingModule.daysUntil(task.dueDate);
          if (days > TASK_LOOKAHEAD_DAYS) return;
          severity = days <= 0 ? 3 : days <= 2 ? 2 : 1;
          detail = BillingModule.relativeLabel(days);
        }

        items.push({
          id: `task:${task.id}`,
          group: "task",
          severity,
          title: task.title,
          detail,
          clientId: client?.id || "",
          clientName: client?.name || "",
          actions: [
            { label: "Hecho", act: "task-done", value: task.id, primary: true },
            ...(task.ref ? [{ label: "Abrir", act: "link", value: task.ref }] : []),
            { label: "Quitar", act: "task-delete", value: task.id, subtle: true },
          ],
        });
      });
    return items;
  }

  /** Recorre todo lo analizado, no solo lo vinculado a un cliente. */
  static #fromSeo(clients) {
    const items = [];

    SeoStore.data.forEach((cached, host) => {
      if (!cached?.signals?.length) return;
      const client = clients.find((c) => GscModule.hostOf(c.siteUrl) === host);

      cached.signals.slice(0, 6).forEach((signal) => {
        items.push({
          id: `seo:${host}:${signal.id}`,
          group: "seo",
          severity: Number(signal.severity) || 1,
          title: signal.title,
          detail: `${signal.detail} · ${signal.metric}`,
          clientId: client?.id || "",
          clientName: client?.name || host,
          actions: [
            { label: "Anotar tarea", act: "signal-task", value: `${host}|${signal.id}`, primary: true },
            ...(signal.url ? [{ label: "Ver página", act: "link", value: signal.url }] : []),
            { label: "Ver sitio", act: "open-seo", value: host },
          ],
        });
      });
    });

    return items;
  }
}

/* ------------------------------------------------------------
   Controlador
   ------------------------------------------------------------ */
class AppController {
  static isLiteDevice() {
    return window.matchMedia("(max-width: 760px), (pointer: coarse)").matches;
  }

  constructor({ clients, requests, tasks }) {
    this.clients = clients.map(BillingModule.migrateClient);
    this.requests = requests;
    this.tasks = tasks;

    this.timerId = null;
    this.toastTimer = null;
    this.editingClientId = null;
    this.notebookClientId = null;
    this.highlightDate = null;
    this.isSaving = false;
    this.agendaFilter = "all";
    this.seoHost = localStorage.getItem(Store.KEYS.seoHost) || "";
    this.seoProperties = [];
    this.seoConnection = null;
    this.seoLoading = false;
    this.warming = false;

    this.isLite = AppController.isLiteDevice();
    this.module = localStorage.getItem(Store.KEYS.module) || "today";
    this.view = this.isLite ? "list" : localStorage.getItem(Store.KEYS.view) || "orbit";
    this.expanded = !this.isLite && localStorage.getItem(Store.KEYS.expanded) === "1";
    this.chartDays = this.isLite ? 14 : CHART_WINDOW_DAYS;
    document.documentElement.classList.toggle("is-lite", this.isLite);

    this.dom = AppController.#collectDom();
  }

  static #collectDom() {
    const ids = [
      "panelToday", "panelClients", "panelSeo",
      "btnModuleToday", "btnModuleClients", "btnModuleSeo",
      "statBilling", "statBillingValue", "statBillingMeta",
      "statMrr", "statMrrValue", "statMrrMeta",
      "statAlerts", "statAlertsValue", "statAlertsMeta",
      "agendaList", "agendaEmpty", "agendaCount", "agendaFilters",
      "agendaDone", "agendaDoneList",
      "swarmGrid", "swarmEmpty", "orbitView", "orbitWeb", "orbitNodes", "orbitCoreValue",
      "cashChartWrap", "chartBars", "chartAxis", "chartPeriodTotal", "chartRangeLabel",
      "btnViewOrbit", "btnViewList", "btnOrbitExpand",
      "requestList", "requestsEmpty",
      "seoClients", "seoNotice", "seoKpis", "seoSpark", "seoInventory",
      "seoSignals", "seoSignalsEmpty", "seoPagesDrawer", "seoPagesCount",
      "seoTableBody", "seoDiagDrawer", "seoDiag", "seoRange",
      "seoClicks", "seoImpressions", "seoCtr", "seoPosition",
      "seoClicksDelta", "seoImpressionsDelta", "seoCtrDelta", "seoPositionDelta",
      "btnGscRefresh", "gscConfigForm", "gscSitemapUrl", "gscPages",
      "clientModal", "requestModal", "taskModal", "notebookModal",
      "clientForm", "clientModalTitle", "clientSubmitBtn", "btnDeleteClient", "clientIdField",
      "clientName", "clientValue", "clientValueLabel", "clientValueHint",
      "clientBillingDate", "clientPhone", "clientSiteUrl", "clientDev",
      "planAnual", "planMensual", "contactActions", "linkWhatsApp", "linkCall",
      "requestForm", "requestClientSelect",
      "taskForm", "taskTitle", "taskClientSelect", "taskDueDate",
      "notebookTitle", "notebookSite", "notebookList", "notebookEmpty", "notebookForm", "notebookBody",
      "nodeTooltip", "toast",
    ];
    const dom = {};
    ids.forEach((id) => {
      dom[id] = document.getElementById(id);
    });
    dom.tooltip = dom.nodeTooltip;
    return dom;
  }

  async init() {
    ["clientModal", "requestModal", "taskModal", "notebookModal"].forEach((id) => this.closeModal(id));
    this.dom.btnViewOrbit.classList.toggle("is-active", this.view === "orbit");
    this.dom.btnViewList.classList.toggle("is-active", this.view === "list");
    this.applyExpanded();
    this.bindEvents();
    this.setModule(this.module, { persist: false, silent: true });

    if (this.clients.some((c) => !c.nextBillingDate)) await this.persistState();

    this.renderAll();
    this.timerId = setInterval(() => this.renderAgenda(), TIMER_TICK_MS);
    this.warmSeo();
  }

  async persistState() {
    if (this.isSaving) return;
    this.isSaving = true;
    try {
      await Store.save(this.clients, this.requests, this.tasks);
    } catch (err) {
      this.showToast(err.message || "Error al guardar");
      throw err;
    } finally {
      this.isSaving = false;
    }
  }

  /* ---------- Eventos ---------- */
  bindEvents() {
    this.dom.btnModuleToday.addEventListener("click", () => this.setModule("today"));
    this.dom.btnModuleClients.addEventListener("click", () => this.setModule("clients"));
    this.dom.btnModuleSeo.addEventListener("click", () => this.setModule("seo"));
    this.dom.statBilling.addEventListener("click", () => this.setModule("clients"));
    this.dom.statMrr.addEventListener("click", () => this.setModule("clients"));
    this.dom.statAlerts.addEventListener("click", () => this.setModule("seo"));

    this.dom.agendaFilters.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-filter]");
      if (!btn) return;
      this.agendaFilter = btn.dataset.filter;
      this.dom.agendaFilters.querySelectorAll(".filter").forEach((el) => {
        el.classList.toggle("is-active", el === btn);
      });
      this.renderAgenda();
    });

    this.dom.agendaList.addEventListener("click", (e) => this.handleAgendaClick(e));
    this.dom.agendaDoneList.addEventListener("click", (e) => this.handleAgendaClick(e));

    document.getElementById("btnOpenClientModal").addEventListener("click", () => this.openCreateClient());
    document.getElementById("btnOpenRequestModal").addEventListener("click", () => this.openRequestModal());
    this.dom.btnOpenTaskModal = document.getElementById("btnOpenTaskModal");
    this.dom.btnOpenTaskModal.addEventListener("click", () => this.openTaskModal());

    document.querySelectorAll("[data-close]").forEach((btn) =>
      btn.addEventListener("click", () => this.closeModal(btn.dataset.close))
    );
    [this.dom.clientModal, this.dom.requestModal, this.dom.taskModal, this.dom.notebookModal].forEach(
      (backdrop) =>
        backdrop.addEventListener("click", (e) => {
          if (e.target === backdrop) this.closeModal(backdrop.id);
        })
    );
    document.addEventListener("keydown", (e) => {
      if (e.key !== "Escape") return;
      ["clientModal", "requestModal", "taskModal", "notebookModal"].forEach((id) => this.closeModal(id));
    });

    this.dom.clientForm.addEventListener("submit", (e) => this.handleClientSubmit(e));
    this.dom.clientForm.addEventListener("change", (e) => {
      if (e.target.name === "planType") this.updateValueFieldHint();
    });
    this.dom.btnDeleteClient.addEventListener("click", () => this.deleteClient(this.editingClientId));
    this.dom.clientPhone.addEventListener("input", () => this.refreshContactLinks());
    this.dom.requestForm.addEventListener("submit", (e) => this.handleAddRequest(e));
    this.dom.taskForm.addEventListener("submit", (e) => this.handleAddTask(e));
    this.dom.notebookForm.addEventListener("submit", (e) => this.handleAddNote(e));
    this.dom.notebookList.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-delete-note]");
      if (btn) this.deleteNote(btn.dataset.deleteNote);
    });

    this.dom.seoClients.addEventListener("click", (e) => {
      const chip = e.target.closest("[data-host]");
      if (chip) this.selectSeoHost(chip.dataset.host);
    });
    this.dom.seoSignals.addEventListener("click", (e) => this.handleAgendaClick(e));
    this.dom.btnGscRefresh.addEventListener("click", () => this.loadSeoHost(this.seoHost, true));
    this.dom.gscConfigForm.addEventListener("submit", (e) => this.handleGscConfig(e));

    this.dom.btnViewOrbit.addEventListener("click", () => this.setView("orbit"));
    this.dom.btnViewList.addEventListener("click", () => this.setView("list"));
    this.dom.btnOrbitExpand.addEventListener("click", () => this.toggleExpanded());

    this.dom.orbitNodes.addEventListener("click", (e) => {
      const node = e.target.closest(".orbit-node");
      if (!node) return;
      this.hideTooltip();
      this.openNotebook(node.dataset.id);
    });

    this.dom.swarmGrid.addEventListener("click", (e) => {
      const map = {
        "[data-mark-live]": (el) => this.markClientLive(el.dataset.markLive),
        "[data-mark-paid]": (el) => this.markClientPaid(el.dataset.markPaid),
        "[data-notebook]": (el) => this.openNotebook(el.dataset.notebook),
        "[data-edit-client]": (el) => this.openEditClient(el.dataset.editClient),
        "[data-delete-client]": (el) => this.deleteClient(el.dataset.deleteClient),
      };
      for (const [selector, handler] of Object.entries(map)) {
        const el = e.target.closest(selector);
        if (el) {
          e.stopPropagation();
          handler(el);
          return;
        }
      }
    });

    this.dom.requestList.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-complete]");
      if (btn) this.completeRequest(btn.dataset.complete);
    });

    if (!this.isLite) {
      this.dom.swarmGrid.addEventListener("mouseover", (e) => this.handleNodeHover(e));
      this.dom.swarmGrid.addEventListener("mousemove", (e) => this.moveTooltip(e));
      this.dom.swarmGrid.addEventListener("mouseout", (e) => {
        if (e.target.closest(".node, .timeline-row") && !e.relatedTarget?.closest?.("#swarmGrid")) {
          this.hideTooltip();
          this.setChartHighlight(null);
        }
      });
      this.dom.chartBars.addEventListener("mouseover", (e) => {
        const bar = e.target.closest(".chart-bar");
        if (bar) this.setChartHighlight(bar.dataset.date);
      });
      this.dom.chartBars.addEventListener("mouseleave", () => this.setChartHighlight(null));
      this.dom.orbitNodes.addEventListener("mouseover", (e) => this.handleNodeHover(e));
      this.dom.orbitNodes.addEventListener("mousemove", (e) => this.moveTooltip(e));
      this.dom.orbitView.addEventListener("mouseleave", () => {
        this.hideTooltip();
        this.setChartHighlight(null);
      });
    }
  }

  handleAgendaClick(e) {
    const btn = e.target.closest("[data-act]");
    if (!btn) return;
    const { act, value } = btn.dataset;

    switch (act) {
      case "paid":
        this.markClientPaid(value);
        break;
      case "activate":
        this.markClientLive(value);
        break;
      case "request-done":
        this.completeRequest(value);
        break;
      case "task-done":
        this.toggleTask(value);
        break;
      case "task-delete":
        this.deleteTask(value);
        break;
      case "signal-task":
        this.taskFromSignal(value);
        break;
      case "open-seo":
        this.selectSeoHost(value);
        this.setModule("seo");
        break;
      case "link":
        window.open(value, "_blank", "noopener");
        break;
      default:
        break;
    }
  }

  /* ---------- Navegación ---------- */
  setModule(module, { persist = true, silent = false } = {}) {
    const valid = ["today", "clients", "seo"];
    this.module = valid.includes(module) ? module : "today";
    if (persist) localStorage.setItem(Store.KEYS.module, this.module);

    const map = {
      today: [this.dom.btnModuleToday, this.dom.panelToday],
      clients: [this.dom.btnModuleClients, this.dom.panelClients],
      seo: [this.dom.btnModuleSeo, this.dom.panelSeo],
    };
    Object.entries(map).forEach(([key, [btn, panel]]) => {
      btn.classList.toggle("is-active", key === this.module);
      panel.hidden = key !== this.module;
    });

    if (this.module === "clients") this.renderSwarm();
    if (this.module === "seo" && !silent) this.renderSeo();
  }

  setView(view) {
    this.view = view;
    localStorage.setItem(Store.KEYS.view, view);
    this.dom.btnViewOrbit.classList.toggle("is-active", view === "orbit");
    this.dom.btnViewList.classList.toggle("is-active", view === "list");
    this.renderSwarm();
  }

  applyExpanded() {
    this.dom.panelClients.classList.toggle("is-expanded", this.expanded);
    this.dom.btnOrbitExpand.setAttribute("aria-pressed", String(this.expanded));
    this.dom.btnOrbitExpand.textContent = this.expanded ? "⤡" : "⤢";
  }

  toggleExpanded() {
    this.expanded = !this.expanded;
    localStorage.setItem(Store.KEYS.expanded, this.expanded ? "1" : "0");
    if (this.expanded && this.view !== "orbit") this.setView("orbit");
    this.applyExpanded();
  }

  showToast(message) {
    this.dom.toast.textContent = message;
    this.dom.toast.hidden = false;
    clearTimeout(this.toastTimer);
    this.toastTimer = setTimeout(() => {
      this.dom.toast.hidden = true;
    }, 2800);
  }

  openModal(id) {
    this.dom[id].hidden = false;
    this.dom[id].querySelector("input, select, textarea")?.focus();
  }

  closeModal(id) {
    this.dom[id].hidden = true;
  }

  /* ---------- Render raíz ---------- */
  renderAll() {
    this.renderStats();
    this.renderAgenda();
    this.renderChart();
    this.renderSwarm();
    this.renderRequests();
  }

  renderStats() {
    const active = this.activeClients();
    const metrics = FinanceModule.computeMetrics(this.clients);

    const dueSoon = active.filter((c) => BillingModule.daysUntil(c.nextBillingDate) <= 7);
    const dueTotal = dueSoon.reduce((sum, c) => sum + (Number(c.valueCLP) || 0), 0);
    const overdue = dueSoon.filter((c) => BillingModule.daysUntil(c.nextBillingDate) < 0).length;

    this.dom.statBillingValue.textContent = FinanceModule.formatCLP(dueTotal);
    this.dom.statBillingMeta.textContent = dueSoon.length
      ? `${dueSoon.length} en 7 días${overdue ? ` · ${overdue} vencido${overdue === 1 ? "" : "s"}` : ""}`
      : "Nada por cobrar";
    this.dom.statBilling.classList.toggle("is-alert", overdue > 0);

    this.dom.statMrrValue.textContent = FinanceModule.formatCLP(metrics.mrr);
    this.dom.statMrrMeta.textContent = `${metrics.activeCount} activo${metrics.activeCount === 1 ? "" : "s"}${metrics.devCount ? ` · ${metrics.devCount} en dev` : ""}`;

    const hosts = this.seoHosts();
    const scanned = hosts.filter((h) => SeoStore.get(h.host));
    const critical = scanned.reduce(
      (sum, h) => sum + (SeoStore.get(h.host)?.signals || []).filter((s) => s.severity >= 3).length,
      0
    );
    this.dom.statAlertsValue.textContent = scanned.length ? String(critical) : "—";
    this.dom.statAlertsMeta.textContent = hosts.length
      ? `${scanned.length}/${hosts.length} sitios revisados`
      : "Agrega el sitio de un cliente";
    this.dom.statAlerts.classList.toggle("is-alert", critical > 0);
  }

  /* ---------- Hoy ---------- */
  renderAgenda() {
    const items = Agenda.build({
      clients: this.clients,
      requests: this.requests,
      tasks: this.tasks,
    });
    const visible =
      this.agendaFilter === "all" ? items : items.filter((i) => i.group === this.agendaFilter);

    this.dom.agendaCount.textContent = `${items.length} pendiente${items.length === 1 ? "" : "s"}`;
    this.dom.agendaEmpty.hidden = visible.length > 0;
    this.dom.agendaList.innerHTML = "";

    const fragment = document.createDocumentFragment();
    visible.forEach((item) => fragment.appendChild(this.#agendaItem(item)));
    this.dom.agendaList.appendChild(fragment);

    this.renderDoneToday();
  }

  #agendaItem(item) {
    const li = document.createElement("li");
    li.className = `agenda-item agenda-item--s${item.severity} agenda-item--${item.group}`;

    const head = document.createElement("div");
    head.className = "agenda-item__head";

    const tag = document.createElement("span");
    tag.className = "agenda-item__tag";
    tag.textContent = Agenda.GROUPS[item.group] || item.group;
    head.appendChild(tag);

    if (item.clientName) {
      const who = document.createElement("span");
      who.className = "agenda-item__client";
      who.textContent = item.clientName;
      head.appendChild(who);
    }

    const title = document.createElement("strong");
    title.className = "agenda-item__title";
    title.textContent = item.title;

    const detail = document.createElement("p");
    detail.className = "agenda-item__detail";
    detail.textContent = item.detail;

    const actions = document.createElement("div");
    actions.className = "agenda-item__actions";
    (item.actions || []).forEach((action) => {
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = `chip-btn${action.primary ? " chip-btn--primary" : ""}${action.subtle ? " chip-btn--subtle" : ""}`;
      btn.dataset.act = action.act;
      btn.dataset.value = action.value;
      btn.textContent = action.label;
      actions.appendChild(btn);
    });

    li.append(head, title, detail, actions);
    return li;
  }

  renderDoneToday() {
    const today = BillingModule.toISODate(new Date());
    const done = this.tasks.filter(
      (t) => t.doneAt && BillingModule.toISODate(new Date(t.doneAt)) === today
    );
    this.dom.agendaDone.hidden = done.length === 0;
    this.dom.agendaDoneList.innerHTML = "";
    if (!done.length) return;

    const fragment = document.createDocumentFragment();
    done.forEach((task) => {
      const li = document.createElement("li");
      li.className = "agenda-item agenda-item--done";
      const title = document.createElement("strong");
      title.className = "agenda-item__title";
      title.textContent = task.title;
      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = "chip-btn chip-btn--subtle";
      btn.dataset.act = "task-done";
      btn.dataset.value = task.id;
      btn.textContent = "Reabrir";
      li.append(title, btn);
      fragment.appendChild(li);
    });
    this.dom.agendaDoneList.appendChild(fragment);
  }

  /* ---------- Tareas ---------- */
  openTaskModal(prefill = {}) {
    this.dom.taskForm.reset();
    this.populateSelect(this.dom.taskClientSelect, { includeEmpty: "Sin cliente" });
    if (prefill.title) this.dom.taskTitle.value = prefill.title;
    if (prefill.clientId) this.dom.taskClientSelect.value = prefill.clientId;
    this.openModal("taskModal");
  }

  async handleAddTask(e) {
    e.preventDefault();
    const data = new FormData(this.dom.taskForm);
    const title = String(data.get("title") || "").trim();
    if (!title) return;

    await this.addTask({
      title,
      clientId: String(data.get("clientId") || ""),
      dueDate: String(data.get("dueDate") || ""),
      kind: "manual",
    });
    this.closeModal("taskModal");
  }

  async addTask({ title, clientId = "", dueDate = "", kind = "manual", ref = "" }) {
    const task = {
      id: crypto.randomUUID(),
      title,
      clientId,
      dueDate,
      kind,
      ref,
      createdAt: Date.now(),
      doneAt: 0,
    };
    this.tasks.push(task);
    try {
      await this.persistState();
      this.renderAgenda();
      this.showToast("Tarea agregada");
    } catch {
      this.tasks.pop();
    }
  }

  async toggleTask(id) {
    const task = this.tasks.find((t) => t.id === id);
    if (!task) return;
    const prev = task.doneAt;
    task.doneAt = prev ? 0 : Date.now();
    try {
      await this.persistState();
      this.renderAgenda();
    } catch {
      task.doneAt = prev;
    }
  }

  async deleteTask(id) {
    const prev = this.tasks;
    this.tasks = this.tasks.filter((t) => t.id !== id);
    try {
      await this.persistState();
      this.renderAgenda();
    } catch {
      this.tasks = prev;
    }
  }

  /** Convierte una señal SEO en tarea, evitando duplicar la misma señal. */
  async taskFromSignal(value) {
    const [host, ...rest] = String(value).split("|");
    const signalId = rest.join("|");
    const cached = SeoStore.get(host);
    const signal = (cached?.signals || []).find((s) => s.id === signalId);
    if (!signal) return;

    if (this.tasks.some((t) => !t.doneAt && t.ref === signal.url && t.kind === signal.kind)) {
      this.showToast("Ya está en tu lista");
      return;
    }

    const client = this.clients.find((c) => GscModule.hostOf(c.siteUrl) === host);
    await this.addTask({
      title: signal.title,
      clientId: client?.id || "",
      kind: signal.kind,
      ref: signal.url || "",
    });
  }

  /* ---------- SEO ---------- */
  /**
   * Sitios operables: los de clientes con web asociada, más las propiedades que
   * el bot ya ve en Search Console (aunque aún no estén vinculadas a un cliente).
   */
  seoHosts() {
    const seen = new Map();

    this.clients.forEach((client) => {
      const host = GscModule.hostOf(client.siteUrl);
      if (!host || seen.has(host)) return;
      seen.set(host, { host, label: client.name, clientId: client.id });
    });

    [...this.seoProperties.map((p) => p.host), ...(this.seoConnection?.hosts || [])].forEach(
      (host) => {
        if (!host || seen.has(host)) return;
        seen.set(host, { host, label: host, clientId: "" });
      }
    );

    return [...seen.values()];
  }

  /** Precarga secuencial: la caché de 15 min del servidor hace baratas las recargas. */
  async warmSeo() {
    if (this.warming) return;
    this.warming = true;

    try {
      this.seoConnection = await GscModule.sites();
      this.seoProperties = this.seoConnection.properties || [];
    } catch (err) {
      this.seoConnection = { connected: false, error: err.message };
    }
    this.renderStats();
    if (this.module === "seo") this.renderSeo();

    try {
      for (const { host } of this.seoHosts()) {
        if (SeoStore.get(host)) continue;
        try {
          SeoStore.set(host, await GscModule.site(host));
        } catch (err) {
          SeoStore.set(host, { error: err.message, signals: [] });
        }
        this.renderStats();
        this.renderAgenda();
        if (this.module === "seo") this.renderSeo();
      }
    } finally {
      this.warming = false;
    }
  }

  selectSeoHost(host) {
    this.seoHost = host;
    localStorage.setItem(Store.KEYS.seoHost, host);
    this.renderSeo();
    if (!SeoStore.get(host)) this.loadSeoHost(host);
  }

  async loadSeoHost(host, fresh = false) {
    if (!host || this.seoLoading) return;
    this.seoLoading = true;
    this.dom.btnGscRefresh.disabled = true;
    this.dom.seoNotice.hidden = false;
    this.dom.seoNotice.className = "notice";
    this.dom.seoNotice.textContent = "Consultando Search Console…";
    try {
      SeoStore.set(host, await GscModule.site(host, fresh));
    } catch (err) {
      SeoStore.set(host, { error: err.message, signals: [] });
    } finally {
      this.seoLoading = false;
      this.dom.btnGscRefresh.disabled = false;
      this.renderSeo();
      this.renderStats();
      this.renderAgenda();
    }
  }

  renderSeo() {
    const hosts = this.seoHosts();
    if (!hosts.some((h) => h.host === this.seoHost)) {
      this.seoHost = hosts[0]?.host || "";
    }
    this.renderSeoPicker(hosts);

    const data = this.seoHost ? SeoStore.get(this.seoHost) : null;
    const hasMetrics = Boolean(data?.totals);

    this.dom.seoKpis.hidden = !hasMetrics;
    this.dom.seoInventory.hidden = !data?.inventory?.total;
    this.dom.seoPagesDrawer.hidden = !data?.pages?.length;

    if (this.seoConnection && !this.seoConnection.connected) {
      this.#seoNotice(
        "Falta la cuenta de servicio en el servidor (ers/data/gsc-service-account.json).",
        "warn"
      );
    } else if (!hosts.length) {
      this.#seoNotice(
        "Ningún sitio disponible. Agrega el sitio web de un cliente en la pestaña Clientes.",
        "warn"
      );
      this.dom.seoSignals.innerHTML = "";
      this.dom.seoSignalsEmpty.hidden = true;
      return;
    } else if (data?.error) {
      this.#seoNotice(data.error, "warn");
    } else if (!data) {
      this.#seoNotice("Sin datos cargados para este sitio.", "");
    } else {
      this.dom.seoNotice.hidden = true;
    }

    if (hasMetrics) this.renderSeoMetrics(data);
    this.renderSeoInventory(data);
    this.renderSeoSignals(data);
    this.renderSeoPages(data);
    this.renderSeoDiagnostics(data);
    this.syncSeoForm(data);
  }

  #seoNotice(text, tone) {
    this.dom.seoNotice.hidden = false;
    this.dom.seoNotice.className = `notice${tone ? ` notice--${tone}` : ""}`;
    this.dom.seoNotice.textContent = text;
  }

  renderSeoPicker(hosts) {
    this.dom.seoClients.innerHTML = "";
    const fragment = document.createDocumentFragment();

    hosts.forEach(({ host, label }) => {
      const data = SeoStore.get(host);
      const critical = (data?.signals || []).filter((s) => s.severity >= 3).length;

      const btn = document.createElement("button");
      btn.type = "button";
      btn.className = `site-chip${host === this.seoHost ? " is-active" : ""}`;
      btn.dataset.host = host;
      btn.setAttribute("role", "tab");
      btn.setAttribute("aria-selected", String(host === this.seoHost));

      const name = document.createElement("strong");
      name.textContent = label;
      const domain = document.createElement("small");
      domain.textContent = host;
      btn.append(name, domain);

      if (data?.error) {
        const badge = document.createElement("span");
        badge.className = "site-chip__badge site-chip__badge--issue";
        badge.textContent = "!";
        btn.appendChild(badge);
      } else if (critical) {
        const badge = document.createElement("span");
        badge.className = "site-chip__badge";
        badge.textContent = String(critical);
        btn.appendChild(badge);
      } else if (!data) {
        const badge = document.createElement("span");
        badge.className = "site-chip__badge site-chip__badge--idle";
        badge.textContent = "…";
        btn.appendChild(badge);
      }

      fragment.appendChild(btn);
    });

    this.dom.seoClients.appendChild(fragment);
  }

  renderSeoMetrics(data) {
    const totals = data.totals;
    const delta = data.totalsDelta || {};

    if (data.range) {
      this.dom.seoRange.textContent = `${data.range.start} → ${data.range.end}${data.cached ? " · caché" : ""}`;
    }

    this.dom.seoClicks.textContent = GscModule.fmt(totals.clicks);
    this.dom.seoImpressions.textContent = GscModule.fmt(totals.impressions);
    this.dom.seoCtr.textContent = GscModule.pct(totals.ctr);
    this.dom.seoPosition.textContent = GscModule.pos(totals.position);

    const pairs = [
      [this.dom.seoClicksDelta, delta.clicks, {}],
      [this.dom.seoImpressionsDelta, delta.impressions, {}],
      [this.dom.seoCtrDelta, delta.ctr, { percent: true }],
      [this.dom.seoPositionDelta, delta.position, { invert: true, position: true }],
    ];
    pairs.forEach(([el, value, opts]) => {
      const { text, cls } = GscModule.delta(value, opts);
      el.textContent = text;
      el.className = cls;
    });

    this.renderSpark(data.daily || []);
  }

  renderSpark(daily) {
    this.dom.seoSpark.innerHTML = "";
    if (!daily.length) return;
    const max = Math.max(...daily.map((d) => d.clicks), 1);
    const fragment = document.createDocumentFragment();
    daily.forEach((day) => {
      const bar = document.createElement("span");
      bar.className = "spark__bar";
      bar.style.setProperty("--h", `${Math.max((day.clicks / max) * 100, 2)}%`);
      bar.title = `${day.date}: ${GscModule.fmt(day.clicks)} clics`;
      fragment.appendChild(bar);
    });
    this.dom.seoSpark.appendChild(fragment);
  }

  renderSeoInventory(data) {
    const inv = data?.inventory;
    if (!inv?.total) {
      this.dom.seoInventory.hidden = true;
      return;
    }

    const cells = [
      { label: "Páginas", value: inv.total },
      { label: "Con tráfico", value: inv.withData, tone: "ok" },
      { label: "Sin impresiones", value: inv.noData, tone: inv.noData ? "warn" : "" },
      { label: "Indexadas", value: `${inv.indexed}/${inv.checked || 0}`, tone: "ok" },
      { label: "Fuera del índice", value: inv.notIndexed, tone: inv.notIndexed ? "bad" : "" },
    ];

    this.dom.seoInventory.hidden = false;
    this.dom.seoInventory.innerHTML = "";
    const fragment = document.createDocumentFragment();
    cells.forEach((cell) => {
      const box = document.createElement("div");
      box.className = `inventory__cell${cell.tone ? ` is-${cell.tone}` : ""}`;
      const value = document.createElement("strong");
      value.textContent = String(cell.value);
      const label = document.createElement("span");
      label.textContent = cell.label;
      box.append(value, label);
      fragment.appendChild(box);
    });
    this.dom.seoInventory.appendChild(fragment);
  }

  renderSeoSignals(data) {
    const signals = data?.signals || [];
    this.dom.seoSignals.innerHTML = "";
    this.dom.seoSignalsEmpty.hidden = signals.length > 0 || !data?.totals;

    const fragment = document.createDocumentFragment();
    signals.forEach((signal) => {
      fragment.appendChild(
        this.#agendaItem({
          id: signal.id,
          group: "seo",
          severity: Number(signal.severity) || 1,
          title: signal.title,
          detail: signal.detail,
          clientName: signal.metric,
          actions: [
            {
              label: "Anotar tarea",
              act: "signal-task",
              value: `${this.seoHost}|${signal.id}`,
              primary: true,
            },
            ...(signal.url ? [{ label: "Abrir", act: "link", value: signal.url }] : []),
          ],
        })
      );
    });
    this.dom.seoSignals.appendChild(fragment);
  }

  renderSeoPages(data) {
    const pages = data?.pages || [];
    this.dom.seoPagesCount.textContent = pages.length ? `(${pages.length})` : "";
    this.dom.seoTableBody.innerHTML = "";
    if (!pages.length) return;

    const fragment = document.createDocumentFragment();
    pages.forEach((row) => {
      const tr = document.createElement("tr");
      if (row.error) tr.classList.add("is-blocked");

      const name = document.createElement("td");
      const link = document.createElement("a");
      link.href = row.url;
      link.target = "_blank";
      link.rel = "noopener";
      link.className = "seo-page-link";
      link.textContent = String(row.url || "").replace(/^https?:\/\/[^/]+/, "") || "/";
      name.appendChild(link);

      if (row.thermometer?.label) {
        const tag = document.createElement("span");
        tag.className = `seo-tag is-${row.thermometer.tone || "neutral"}`;
        tag.textContent = row.thermometer.label;
        name.appendChild(tag);
      }
      if (Array.isArray(row.queries) && row.queries.length) {
        const queries = document.createElement("div");
        queries.className = "seo-query-list";
        row.queries.slice(0, 3).forEach((item) => {
          const chip = document.createElement("span");
          chip.className = "seo-query";
          chip.textContent = `${item.query} · ${GscModule.fmt(item.clicks)}c`;
          queries.appendChild(chip);
        });
        name.appendChild(queries);
      }

      const current = row.current || {};
      const cell = (raw, formatter, deltaValue, opts) => {
        const td = document.createElement("td");
        if (row.error) {
          td.textContent = "—";
          return td;
        }
        td.textContent = formatter(raw);
        const { text, cls } = GscModule.delta(deltaValue, opts);
        if (text !== "sin cambio") {
          const span = document.createElement("span");
          span.className = `seo-delta ${cls}`;
          span.textContent = text;
          td.appendChild(span);
        }
        return td;
      };

      const state = document.createElement("td");
      if (row.error) {
        state.textContent = "—";
      } else if (row.indexStatus?.label) {
        const badge = document.createElement("span");
        badge.className = `seo-index is-${row.indexStatus.tone || "neutral"}`;
        badge.textContent = row.indexStatus.label;
        state.appendChild(badge);
      } else {
        state.textContent = "—";
      }

      tr.append(
        name,
        cell(current.clicks, GscModule.fmt, row.delta?.clicks, {}),
        cell(current.impressions, GscModule.fmt, row.delta?.impressions, {}),
        cell(current.ctr, GscModule.pct, row.delta?.ctr, { percent: true }),
        cell(current.position, GscModule.pos, row.delta?.position, { invert: true, position: true }),
        state
      );
      fragment.appendChild(tr);
    });
    this.dom.seoTableBody.appendChild(fragment);
  }

  renderSeoDiagnostics(data) {
    const diag = data?.diagnostics || {};
    const rows = [
      ["Cuenta de servicio", this.seoConnection?.serviceEmail || "no detectada"],
      ["Propiedad usada", diag.property || "sin resolver"],
      ["Sitemap leído", diag.sitemapSource || diag.sitemapUrl || "no detectado"],
      [
        "Origen de las URLs",
        `${diag.fromSitemap || 0} del sitemap · ${diag.fromManual || 0} manuales`,
      ],
      [
        "Propiedades visibles",
        (this.seoProperties.map((p) => p.property).join(", ") ||
          this.seoConnection?.error ||
          "ninguna"),
      ],
      ["Versión API", data?.version || this.seoConnection?.version || "—"],
    ];
    if (diag.sitemapError) rows.push(["Error de sitemap", diag.sitemapError]);
    if (diag.sitemapApiError) rows.push(["Error de la API de sitemaps", diag.sitemapApiError]);

    this.dom.seoDiag.innerHTML = "";
    const fragment = document.createDocumentFragment();
    rows.forEach(([label, value]) => {
      const row = document.createElement("div");
      row.className = "diag__row";
      const key = document.createElement("span");
      key.textContent = label;
      const val = document.createElement("strong");
      val.textContent = value;
      row.append(key, val);
      fragment.appendChild(row);
    });
    this.dom.seoDiag.appendChild(fragment);
  }

  syncSeoForm(data) {
    if (document.activeElement === this.dom.gscSitemapUrl || document.activeElement === this.dom.gscPages) {
      return;
    }
    this.dom.gscSitemapUrl.value = data?.diagnostics?.sitemapUrl || "";
    const manual = (data?.pages || []).filter((p) => p.label && p.label !== p.url);
    this.dom.gscPages.value = GscModule.serializePages(manual);
  }

  async handleGscConfig(e) {
    e.preventDefault();
    if (!this.seoHost) return;
    try {
      await GscModule.saveConfig({
        host: this.seoHost,
        sitemapUrl: this.dom.gscSitemapUrl.value.trim(),
        pages: GscModule.parsePages(this.dom.gscPages.value),
      });
      this.showToast("Configuración guardada");
      await this.loadSeoHost(this.seoHost, true);
    } catch (err) {
      this.showToast(err.message);
    }
  }

  /* ---------- Clientes ---------- */
  activeClients() {
    return this.clients.filter((c) => !c.inDevelopment);
  }

  populateSelect(select, { includeEmpty = "" } = {}) {
    select.innerHTML = includeEmpty ? `<option value="">${includeEmpty}</option>` : "";
    if (!includeEmpty) {
      select.innerHTML = '<option value="" disabled selected>Selecciona un cliente…</option>';
    }
    this.clients.forEach((c) => {
      const opt = document.createElement("option");
      opt.value = c.id;
      opt.textContent = c.name;
      select.appendChild(opt);
    });
  }

  setDefaultBillingDate() {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    this.dom.clientBillingDate.value = BillingModule.toISODate(d);
  }

  updateValueFieldHint() {
    if (this.dom.planAnual.checked) {
      this.dom.clientValueLabel.textContent = "Valor anual (CLP)";
      this.dom.clientValueHint.textContent = "Se divide entre 12 para el MRR.";
      this.dom.clientValue.placeholder = "Ej: 1200000";
    } else if (this.dom.planMensual.checked) {
      this.dom.clientValueLabel.textContent = "Valor mensual (CLP)";
      this.dom.clientValueHint.textContent = "Suma directo al MRR.";
      this.dom.clientValue.placeholder = "Ej: 250000";
    } else {
      this.dom.clientValueLabel.textContent = "Valor del plan (CLP)";
      this.dom.clientValueHint.textContent = "Elige el tipo de plan.";
    }
  }

  openCreateClient() {
    this.editingClientId = null;
    this.dom.clientForm.reset();
    this.dom.clientIdField.value = "";
    this.dom.clientModalTitle.textContent = "Nuevo cliente";
    this.dom.btnDeleteClient.hidden = true;
    this.updateValueFieldHint();
    this.setDefaultBillingDate();
    this.openModal("clientModal");
  }

  openEditClient(id) {
    const client = this.clients.find((c) => c.id === id);
    if (!client) return;

    this.editingClientId = id;
    this.dom.clientIdField.value = id;
    this.dom.clientName.value = client.name;
    this.dom.clientValue.value = client.valueCLP;
    this.dom.clientBillingDate.value = client.nextBillingDate;
    this.dom.clientPhone.value = client.phone ?? "";
    this.dom.clientSiteUrl.value = client.siteUrl ?? "";
    this.dom.clientDev.checked = Boolean(client.inDevelopment);
    (client.planType === "anual" ? this.dom.planAnual : this.dom.planMensual).checked = true;

    this.dom.clientModalTitle.textContent = client.name;
    this.dom.btnDeleteClient.hidden = false;
    this.updateValueFieldHint();
    this.refreshContactLinks();
    this.openModal("clientModal");
  }

  refreshContactLinks() {
    const wa = ContactModule.waLink(this.dom.clientPhone.value, this.dom.clientName.value.trim() || "!");
    this.dom.contactActions.hidden = !wa;
    if (wa) {
      this.dom.linkWhatsApp.href = wa;
      this.dom.linkCall.href = ContactModule.telLink(this.dom.clientPhone.value);
    }
  }

  async handleClientSubmit(e) {
    e.preventDefault();
    const data = new FormData(this.dom.clientForm);

    const payload = {
      name: String(data.get("name") || "").trim(),
      planType: data.get("planType"),
      valueCLP: Number(data.get("valueCLP")),
      nextBillingDate: data.get("nextBillingDate"),
      phone: String(data.get("phone") || "").trim(),
      siteUrl: String(data.get("siteUrl") || "").trim(),
      inDevelopment: data.get("inDevelopment") === "on",
    };

    if (!payload.name || !payload.planType || payload.valueCLP <= 0 || !payload.nextBillingDate) return;

    if (this.editingClientId) {
      const idx = this.clients.findIndex((c) => c.id === this.editingClientId);
      if (idx === -1) return;
      this.clients[idx] = { ...this.clients[idx], ...payload };
    } else {
      this.clients.push({ id: crypto.randomUUID(), notes: [], ...payload });
    }

    try {
      await this.persistState();
      this.showToast(`${payload.name} guardado`);
      this.dom.clientForm.reset();
      this.editingClientId = null;
      this.closeModal("clientModal");
      this.renderAll();
      this.warmSeo();
    } catch {
      /* el toast de error ya se mostró */
    }
  }

  async markClientPaid(id) {
    const client = this.clients.find((c) => c.id === id);
    if (!client) return;
    client.nextBillingDate = BillingModule.advanceBillingDate(client);
    try {
      await this.persistState();
      this.renderAll();
      this.showToast(`Cobrado · próximo ${BillingModule.formatShort(client.nextBillingDate)}`);
    } catch {
      /* ya notificado */
    }
  }

  async markClientLive(id) {
    const client = this.clients.find((c) => c.id === id);
    if (!client) return;
    client.inDevelopment = false;
    try {
      await this.persistState();
      this.renderAll();
      this.showToast(`${client.name} activado`);
    } catch {
      client.inDevelopment = true;
    }
  }

  async deleteClient(id) {
    if (!id) return;
    const client = this.clients.find((c) => c.id === id);
    if (!client) return;
    if (!confirm(`¿Eliminar a "${client.name}"? Se borran sus solicitudes y tareas.`)) return;

    this.clients = this.clients.filter((c) => c.id !== id);
    this.requests = this.requests.filter((r) => r.clientId !== id);
    this.tasks = this.tasks.filter((t) => t.clientId !== id);

    try {
      await this.persistState();
      this.editingClientId = null;
      this.closeModal("clientModal");
      this.renderAll();
      this.showToast(`${client.name} eliminado`);
    } catch {
      /* ya notificado */
    }
  }

  /* ---------- Solicitudes ---------- */
  openRequestModal() {
    if (this.clients.length === 0) {
      this.openCreateClient();
      return;
    }
    this.populateSelect(this.dom.requestClientSelect);
    this.openModal("requestModal");
  }

  async handleAddRequest(e) {
    e.preventDefault();
    const data = new FormData(this.dom.requestForm);
    const request = {
      id: crypto.randomUUID(),
      clientId: data.get("clientId"),
      description: String(data.get("description") || "").trim(),
      createdAt: Date.now(),
      status: "activa",
    };
    if (!request.clientId || !request.description) return;

    this.requests.push(request);
    try {
      await this.persistState();
      this.dom.requestForm.reset();
      this.closeModal("requestModal");
      this.renderRequests();
      this.renderAgenda();
    } catch {
      this.requests.pop();
    }
  }

  async completeRequest(id) {
    const prev = this.requests;
    this.requests = this.requests.filter((r) => r.id !== id);
    try {
      await this.persistState();
      this.renderRequests();
      this.renderAgenda();
    } catch {
      this.requests = prev;
    }
  }

  renderRequests() {
    const list = this.dom.requestList;
    list.innerHTML = "";
    const active = this.requests.filter((r) => r.status === "activa");
    this.dom.requestsEmpty.hidden = active.length > 0;

    const fragment = document.createDocumentFragment();
    [...active]
      .sort((a, b) => b.createdAt - a.createdAt)
      .forEach((req) => {
        const client = this.clients.find((c) => c.id === req.clientId);
        const hours = Math.floor((Date.now() - Number(req.createdAt)) / 3600000);

        const li = document.createElement("li");
        li.className = "request-card";

        const top = document.createElement("div");
        top.className = "request-card__top";
        const name = document.createElement("span");
        name.className = "request-card__client";
        name.textContent = client?.name ?? "Cliente eliminado";
        top.appendChild(name);

        const desc = document.createElement("p");
        desc.className = "request-card__desc";
        desc.textContent = req.description;

        const bottom = document.createElement("div");
        bottom.className = "request-card__bottom";
        const timer = document.createElement("span");
        timer.className = `timer${hours >= OVERDUE_HOURS ? " is-overdue" : ""}`;
        timer.textContent = hours >= 24 ? `${Math.floor(hours / 24)}d` : `${hours}h`;
        const done = document.createElement("button");
        done.type = "button";
        done.className = "btn-done";
        done.dataset.complete = req.id;
        done.textContent = "Completar";
        bottom.append(timer, done);

        li.append(top, desc, bottom);
        fragment.appendChild(li);
      });
    list.appendChild(fragment);
  }

  /* ---------- Cuaderno ---------- */
  openNotebook(id) {
    const client = this.clients.find((c) => c.id === id);
    if (!client) return;
    this.notebookClientId = id;
    this.dom.notebookTitle.textContent = client.name;
    const site = (client.siteUrl || "").trim();
    this.dom.notebookSite.hidden = !site;
    this.dom.notebookSite.textContent = site;
    this.renderNotebook();
    this.dom.notebookForm.reset();
    this.openModal("notebookModal");
  }

  renderNotebook() {
    const client = this.clients.find((c) => c.id === this.notebookClientId);
    const notes = [...(client?.notes || [])].sort((a, b) => b.createdAt - a.createdAt);
    this.dom.notebookEmpty.hidden = notes.length > 0;
    this.dom.notebookList.innerHTML = "";

    const stamp = new Intl.DateTimeFormat("es-CL", {
      day: "numeric",
      month: "short",
      hour: "2-digit",
      minute: "2-digit",
    });
    const fragment = document.createDocumentFragment();
    notes.forEach((note) => {
      const li = document.createElement("li");
      li.className = "notebook__item";
      const head = document.createElement("div");
      head.className = "notebook__item-head";
      const time = document.createElement("time");
      time.dateTime = new Date(note.createdAt).toISOString();
      time.textContent = stamp.format(note.createdAt);
      const del = document.createElement("button");
      del.type = "button";
      del.className = "notebook__delete";
      del.dataset.deleteNote = note.id;
      del.setAttribute("aria-label", "Eliminar nota");
      del.textContent = "✕";
      head.append(time, del);
      const body = document.createElement("p");
      body.textContent = note.body;
      li.append(head, body);
      fragment.appendChild(li);
    });
    this.dom.notebookList.appendChild(fragment);
  }

  async handleAddNote(e) {
    e.preventDefault();
    const client = this.clients.find((c) => c.id === this.notebookClientId);
    const body = this.dom.notebookBody.value.trim();
    if (!client || !body) return;

    client.notes = Array.isArray(client.notes) ? client.notes : [];
    client.notes.push({ id: crypto.randomUUID(), body, createdAt: Date.now() });
    try {
      await this.persistState();
      this.dom.notebookForm.reset();
      this.renderNotebook();
      this.renderSwarm();
    } catch {
      client.notes.pop();
    }
  }

  async deleteNote(noteId) {
    const client = this.clients.find((c) => c.id === this.notebookClientId);
    if (!client) return;
    const prev = client.notes;
    client.notes = (client.notes || []).filter((n) => n.id !== noteId);
    try {
      await this.persistState();
      this.renderNotebook();
      this.renderSwarm();
    } catch {
      client.notes = prev;
    }
  }

  /* ---------- Tooltip ---------- */
  handleNodeHover(e) {
    const el = e.target.closest(".node, .timeline-row, .orbit-node");
    if (!el) return;
    const client = this.clients.find((c) => c.id === el.dataset.id);
    if (!client) return;

    const days = BillingModule.daysUntil(client.nextBillingDate);
    const tip = this.dom.tooltip;
    tip.querySelector(".node-tooltip__name").textContent = client.name;
    tip.querySelector(".node-tooltip__plan").textContent = client.inDevelopment
      ? `Plan ${client.planType} · En desarrollo`
      : `Plan ${client.planType}`;
    tip.querySelector(".node-tooltip__date").textContent = client.inDevelopment
      ? BillingModule.formatLong(client.nextBillingDate)
      : `${BillingModule.formatLong(client.nextBillingDate)} · ${BillingModule.relativeLabel(days)}`;
    tip.querySelector(".node-tooltip__value").textContent = FinanceModule.formatCLP(client.valueCLP);
    tip.hidden = false;
    this.moveTooltip(e);
    if (!client.inDevelopment) this.setChartHighlight(client.nextBillingDate);
  }

  moveTooltip(e) {
    if (this.dom.tooltip.hidden) return;
    this.dom.tooltip.style.left = `${e.clientX}px`;
    this.dom.tooltip.style.top = `${e.clientY}px`;
  }

  hideTooltip() {
    this.dom.tooltip.hidden = true;
  }

  setChartHighlight(date) {
    this.highlightDate = date;
    this.dom.chartBars.querySelectorAll(".chart-bar").forEach((bar) => {
      bar.classList.toggle("is-active", bar.dataset.date === date);
    });
    this.dom.swarmGrid.querySelectorAll(".timeline-row").forEach((row) => {
      row.classList.toggle("is-highlighted", row.dataset.date === date);
    });
  }

  /* ---------- Cartera ---------- */
  renderChart() {
    const series = BillingModule.buildCashFlowSeries(this.activeClients(), this.chartDays);
    const hasBilling = this.activeClients().length > 0;
    this.dom.cashChartWrap.hidden = !hasBilling;
    if (!hasBilling) return;

    const maxTotal = Math.max(...series.map((b) => b.total), 1);
    const periodTotal = series.reduce((sum, b) => sum + b.total, 0);

    this.dom.chartRangeLabel.textContent = `Próximos ${this.chartDays} días`;
    this.dom.chartPeriodTotal.textContent = FinanceModule.formatCLP(periodTotal);
    this.dom.chartBars.innerHTML = "";

    const fragment = document.createDocumentFragment();
    series.forEach((bucket) => {
      const bar = document.createElement("div");
      bar.className = "chart-bar";
      if (bucket.isToday) bar.classList.add("is-today");
      if (bucket.total > 0) bar.style.setProperty("--bar-h", `${(bucket.total / maxTotal) * 100}%`);
      bar.dataset.date = bucket.date;
      bar.title = bucket.total
        ? `${BillingModule.formatShort(bucket.date)}: ${FinanceModule.formatCLP(bucket.total)}`
        : BillingModule.formatShort(bucket.date);

      const tip = document.createElement("span");
      tip.className = "chart-bar__tip";
      tip.textContent = bucket.total ? FinanceModule.formatCLP(bucket.total) : "—";
      bar.appendChild(tip);
      fragment.appendChild(bar);
    });
    this.dom.chartBars.appendChild(fragment);

    const first = series[0];
    const mid = series[Math.floor(series.length / 2)];
    const last = series[series.length - 1];
    this.dom.chartAxis.innerHTML = `
      <span>${BillingModule.formatShort(first.date)}</span>
      <span>${BillingModule.formatShort(mid.date)}</span>
      <span>${BillingModule.formatShort(last.date)}</span>
    `;
  }

  renderSwarm() {
    const hasClients = this.clients.length > 0;
    this.dom.swarmEmpty.hidden = hasClients;
    const showOrbit = this.view === "orbit" && hasClients;
    this.dom.orbitView.hidden = !showOrbit;
    this.dom.swarmGrid.hidden = !(this.view === "list" && hasClients);

    if (!hasClients) return;
    if (showOrbit) this.renderOrbit();
    else this.renderList();
  }

  /**
   * Mapa orbital: radio = días hasta el cobro, tamaño = valor del contrato,
   * ángulo = distribución áurea (137.5°) para evitar solapamientos.
   */
  renderOrbit() {
    const container = this.dom.orbitNodes;
    container.innerHTML = "";
    this.dom.orbitWeb.innerHTML = "";

    this.dom.orbitCoreValue.textContent = FinanceModule.formatCLP(
      FinanceModule.computeMetrics(this.clients).mrr
    );

    const maxValue = Math.max(...this.clients.map((c) => Number(c.valueCLP) || 0), 1);
    const sorted = BillingModule.sortByBilling(this.clients);
    const fragment = document.createDocumentFragment();
    const GOLDEN_ANGLE = 137.5;

    sorted.forEach((client, i) => {
      const isDev = Boolean(client.inDevelopment);
      const days = BillingModule.daysUntil(client.nextBillingDate);
      const urgency = isDev ? "normal" : BillingModule.urgency(days);

      let radiusPct;
      if (isDev) radiusPct = 42 + (i % 3) * 2;
      else if (days < 0) radiusPct = 12;
      else if (days <= 7) radiusPct = 10 + (days / 7) * 7;
      else if (days <= 14) radiusPct = 17 + ((days - 7) / 7) * 10;
      else if (days <= 30) radiusPct = 27 + ((days - 14) / 16) * 11;
      else radiusPct = Math.min(38 + ((days - 30) / 60) * 8, 46);

      const angleRad = ((i * GOLDEN_ANGLE) % 360) * (Math.PI / 180);
      const x = 50 + radiusPct * Math.cos(angleRad);
      const y = 50 + radiusPct * Math.sin(angleRad);
      const size = Math.round(44 + Math.sqrt((Number(client.valueCLP) || 0) / maxValue) * 40);

      const link = document.createElementNS("http://www.w3.org/2000/svg", "line");
      link.setAttribute("x1", "50");
      link.setAttribute("y1", "50");
      link.setAttribute("x2", String(x));
      link.setAttribute("y2", String(y));
      link.classList.add("orbit-link", isDev ? "orbit-link--dev" : `orbit-link--${client.planType}`);
      if (!isDev && urgency !== "normal") link.classList.add(`orbit-link--${urgency}`);
      this.dom.orbitWeb.appendChild(link);

      const node = document.createElement("div");
      node.className = isDev
        ? "orbit-node orbit-node--dev"
        : `orbit-node orbit-node--${client.planType}${urgency !== "normal" ? ` orbit-node--${urgency}` : ""}`;
      node.dataset.id = client.id;
      node.style.setProperty("--x", `${x}%`);
      node.style.setProperty("--y", `${y}%`);
      node.style.setProperty("--size", `${size}px`);
      node.style.setProperty("--float-speed", `${4.6 + (i % 4) * 0.55}s`);
      node.style.setProperty("--float-delay", `${(i % 5) * -1.2}s`);
      node.setAttribute("role", "button");
      node.setAttribute("aria-label", `${client.name} · abrir cuaderno`);
      node.title = `${client.name} · clic para abrir el cuaderno`;

      const initials = document.createElement("span");
      initials.className = "orbit-node__initials";
      initials.textContent = AppController.getInitials(client.name);

      const amount = document.createElement("span");
      amount.className = "orbit-node__amount";
      amount.textContent = isDev ? "dev" : days < 0 ? "vencido" : `${days}d`;

      node.append(initials, amount);
      fragment.appendChild(node);
    });

    container.appendChild(fragment);
  }

  renderList() {
    const grid = this.dom.swarmGrid;
    grid.innerHTML = "";
    const fragment = document.createDocumentFragment();

    BillingModule.sortByBilling(this.clients).forEach((client, i) => {
      const isDev = Boolean(client.inDevelopment);
      const days = BillingModule.daysUntil(client.nextBillingDate);
      const urgency = isDev ? "dev" : BillingModule.urgency(days);

      const row = document.createElement("article");
      row.className = `timeline-row timeline-row--${urgency}`;
      row.dataset.id = client.id;
      row.dataset.date = client.nextBillingDate;
      row.setAttribute("role", "listitem");
      row.style.animationDelay = `${Math.min(i * 35, 500)}ms`;

      const rail = document.createElement("div");
      rail.className = "timeline-row__rail";
      const dot = document.createElement("span");
      dot.className = "timeline-row__dot";
      rail.appendChild(dot);

      const node = document.createElement("div");
      node.className = isDev ? "node node--dev" : `node node--${client.planType} node--${urgency}`;
      node.dataset.id = client.id;
      const dayLabel = document.createElement("span");
      dayLabel.className = "node__day";
      dayLabel.textContent = String(BillingModule.parseDate(client.nextBillingDate).getDate()).padStart(2, "0");
      const initials = document.createElement("span");
      initials.className = "node__initials";
      initials.textContent = AppController.getInitials(client.name);
      const core = document.createElement("span");
      core.className = "node__core";
      node.append(dayLabel, initials, core);

      const meta = document.createElement("div");
      meta.className = "timeline-row__meta";
      const dateEl = document.createElement("span");
      dateEl.className = "timeline-row__date";
      dateEl.textContent = BillingModule.formatShort(client.nextBillingDate);
      const nameEl = document.createElement("strong");
      nameEl.className = "timeline-row__name";
      nameEl.textContent = client.name;
      if (isDev) {
        const tag = document.createElement("span");
        tag.className = "tag-dev";
        tag.textContent = "Dev";
        nameEl.appendChild(tag);
      }
      const sub = document.createElement("div");
      sub.className = "timeline-row__sub";
      const countdown = document.createElement("span");
      countdown.className = "timeline-row__countdown";
      countdown.textContent = isDev ? "En desarrollo" : BillingModule.relativeLabel(days);
      const value = document.createElement("em");
      value.textContent = FinanceModule.formatCLP(client.valueCLP);
      sub.append(countdown, value);
      meta.append(dateEl, nameEl, sub);

      const actions = document.createElement("div");
      actions.className = "timeline-row__actions";

      const primary = document.createElement("button");
      primary.type = "button";
      if (isDev) {
        primary.className = "btn-row btn-row--live";
        primary.dataset.markLive = client.id;
        primary.textContent = "Activar";
      } else {
        primary.className = "btn-row btn-row--paid";
        primary.dataset.markPaid = client.id;
        primary.textContent = "Cobrado";
      }
      actions.appendChild(primary);

      const waHref = ContactModule.waLink(client.phone, client.name);
      if (waHref) {
        const wa = document.createElement("a");
        wa.className = "btn-row btn-row--wa";
        wa.href = waHref;
        wa.target = "_blank";
        wa.rel = "noopener";
        wa.textContent = "WhatsApp";
        actions.appendChild(wa);
      }

      const noteBtn = document.createElement("button");
      noteBtn.type = "button";
      noteBtn.className = "btn-row btn-row--edit";
      noteBtn.dataset.notebook = client.id;
      const noteCount = (client.notes || []).length;
      noteBtn.textContent = noteCount ? `Cuaderno (${noteCount})` : "Cuaderno";

      const editBtn = document.createElement("button");
      editBtn.type = "button";
      editBtn.className = "btn-row btn-row--edit";
      editBtn.dataset.editClient = client.id;
      editBtn.textContent = "Editar";

      actions.append(noteBtn, editBtn);
      row.append(rail, node, meta, actions);
      fragment.appendChild(row);
    });

    grid.appendChild(fragment);
    if (this.highlightDate) this.setChartHighlight(this.highlightDate);
  }

  static getInitials(name) {
    return String(name)
      .split(/\s+/)
      .slice(0, 2)
      .map((w) => w[0]?.toUpperCase() ?? "")
      .join("");
  }
}

/* ------------------------------------------------------------
   Bootstrap
   ------------------------------------------------------------ */
document.addEventListener("DOMContentLoaded", async () => {
  const boot = document.getElementById("bootScreen");
  const bootError = document.getElementById("bootError");

  try {
    SeoStore.load();
    const data = await Store.init();
    boot.hidden = true;
    await new AppController(data).init();
  } catch (err) {
    boot.hidden = true;
    bootError.hidden = false;
    bootError.querySelector("p").textContent =
      err.message || "No se pudo conectar con el servidor. Verifica PHP y los permisos de /data.";
  }
});
