"use strict";

/* ============================================================
   MONO STUDIO OS — Núcleo de la aplicación
   Arquitectura: Store (persistencia) → Módulos de dominio →
   CRMController (orquestación de estado, DOM y eventos).
   ============================================================ */

/** Umbral en horas a partir del cual una solicitud se marca en rojo. */
const OVERDUE_HOURS = 48;

/** Ventana del gráfico de flujo de cobros (días). */
const CHART_WINDOW_DAYS = 30;

/** Días para marcar un cobro como urgente (ámbar). */
const URGENT_DAYS = 3;

/** Frecuencia de refresco de cronómetros: 60s (la UI muestra hh:mm). */
const TIMER_TICK_MS = 60 * 1000;

/* ------------------------------------------------------------
   Capa de persistencia
   ------------------------------------------------------------ */
class Store {
  static KEYS = {
    legacyClients: "monoStudio.clients",
    legacyRequests: "monoStudio.requests",
    view: "monoStudio.view",
    expanded: "monoStudio.expanded",
  };

  static API = "./api/store.php";
  static cache = { clients: [], requests: [] };

  /** Carga datos desde el servidor. Migra localStorage si el servidor está vacío. */
  static async init() {
    const res = await fetch(Store.API, { headers: { Accept: "application/json" } });
    if (!res.ok) throw new Error(`No se pudo conectar al servidor (${res.status})`);

    const data = await res.json();
    Store.cache = {
      clients: Array.isArray(data.clients) ? data.clients : [],
      requests: Array.isArray(data.requests) ? data.requests : [],
    };

    const legacyClients = Store.#loadLegacy(Store.KEYS.legacyClients);
    const legacyRequests = Store.#loadLegacy(Store.KEYS.legacyRequests);

    if (Store.cache.clients.length === 0 && legacyClients.length > 0) {
      Store.cache.clients = legacyClients;
      Store.cache.requests = legacyRequests;
      await Store.persist();
      localStorage.removeItem(Store.KEYS.legacyClients);
      localStorage.removeItem(Store.KEYS.legacyRequests);
    }

    return Store.cache;
  }

  static #loadLegacy(key) {
    try {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(raw) : [];
    } catch {
      return [];
    }
  }

  /** Persiste clientes y solicitudes en el servidor. */
  static async persist() {
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

  static async save(clients, requests) {
    Store.cache = { clients, requests };
    await Store.persist();
  }
}

/* ------------------------------------------------------------
   Módulo Financiero
   ------------------------------------------------------------ */
class FinanceModule {
  static #clpFormatter = new Intl.NumberFormat("es-CL", {
    style: "currency",
    currency: "CLP",
    maximumFractionDigits: 0,
  });

  static formatCLP(amount) {
    return FinanceModule.#clpFormatter.format(Math.round(amount));
  }

  /**
   * valueCLP = monto que cobras en cada ciclo del plan:
   *   mensual → valor por mes | anual → valor por año (se prorratea en MRR/ARR).
   *
   * MRR  = mensuales + (anuales ÷ 12)
   * ARR  = anuales + (mensuales × 12)
   */
  static computeMetrics(clients) {
    // Los clientes en desarrollo aún no facturan: no suman a MRR/ARR/portfolio,
    // pero registramos su valor como "pipeline" (ingreso potencial al activarse).
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

    const monthlyMRR = totals.monthly;
    const annualMRR = totals.annual / 12;

    return {
      monthlySum: totals.monthly,
      annualSum: totals.annual,
      monthlyMRR,
      annualMRR,
      portfolio: totals.annual + totals.monthly,
      mrr: monthlyMRR + annualMRR,
      arr: totals.annual + totals.monthly * 12,
      devCount: totals.devCount,
      devPipeline: totals.devPipeline,
    };
  }
}

/* ------------------------------------------------------------
   Módulo de fechas y cobros
   ------------------------------------------------------------ */
class BillingModule {
  static #dateFmt = new Intl.DateTimeFormat("es-CL", { day: "numeric", month: "short" });
  static #dateFmtLong = new Intl.DateTimeFormat("es-CL", {
    weekday: "short",
    day: "numeric",
    month: "long",
    year: "numeric",
  });

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
    const [y, m, d] = iso.split("-").map(Number);
    return new Date(y, m - 1, d);
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
    return BillingModule.#dateFmt.format(BillingModule.parseDate(iso));
  }

  static formatLong(iso) {
    return BillingModule.#dateFmtLong.format(BillingModule.parseDate(iso));
  }

  /** Clientes legacy sin fecha: primer día del mes siguiente. */
  static migrateClient(client) {
    if (!client.nextBillingDate) {
      const d = new Date();
      d.setMonth(d.getMonth() + 1, 1);
      client.nextBillingDate = BillingModule.toISODate(d);
    }
    return client;
  }

  /** Orden: vencidos primero (más antiguo arriba), luego por fecha ascendente. */
  static sortByBilling(clients) {
    return [...clients].sort((a, b) => {
      const da = BillingModule.parseDate(a.nextBillingDate).getTime();
      const db = BillingModule.parseDate(b.nextBillingDate).getTime();
      return da - db;
    });
  }

  static nextBillingClient(clients) {
    const sorted = BillingModule.sortByBilling(clients);
    return sorted.find((c) => BillingModule.daysUntil(c.nextBillingDate) >= 0) ?? sorted[0] ?? null;
  }

  /** Serie diaria de cobros para el gráfico (próximos N días). */
  static buildCashFlowSeries(clients, days = CHART_WINDOW_DAYS) {
    const today = BillingModule.startOfDay();
    const buckets = [];

    for (let i = 0; i < days; i++) {
      const d = new Date(today);
      d.setDate(d.getDate() + i);
      buckets.push({
        date: BillingModule.toISODate(d),
        total: 0,
        clients: [],
        isToday: i === 0,
      });
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

  /** Avanza la fecha al siguiente ciclo según el plan. */
  static advanceBillingDate(client) {
    const d = BillingModule.parseDate(client.nextBillingDate);
    if (client.planType === "anual") {
      d.setFullYear(d.getFullYear() + 1);
    } else {
      d.setMonth(d.getMonth() + 1);
    }
    return BillingModule.toISODate(d);
  }
}

/* ------------------------------------------------------------
   Módulo de contacto (WhatsApp / llamadas)
   ------------------------------------------------------------ */
class ContactModule {
  /**
   * Normaliza a formato E.164 asumiendo Chile como país por defecto:
   * "9 1234 5678" → "56912345678". Si ya trae código país, se respeta.
   */
  static normalizePhone(raw) {
    if (!raw) return null;
    let digits = raw.replace(/\D/g, "");
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
   Controlador principal
   ------------------------------------------------------------ */
class CRMController {
  constructor(clients, requests) {
    this.clients = clients.map(BillingModule.migrateClient);
    this.requests = requests;
    this.timerId = null;
    this.toastTimer = null;
    this.editingClientId = null;
    this.highlightDate = null;
    this.isSaving = false;
    this.view = localStorage.getItem(Store.KEYS.view) || "orbit";
    this.expanded = localStorage.getItem(Store.KEYS.expanded) === "1";

    this.dom = {
      kpiPortfolio: document.getElementById("kpiPortfolio"),
      kpiMRR: document.getElementById("kpiMRR"),
      kpiARR: document.getElementById("kpiARR"),
      kpiPortfolioMeta: document.getElementById("kpiPortfolioMeta"),
      kpiMRRMeta: document.getElementById("kpiMRRMeta"),
      kpiARRMeta: document.getElementById("kpiARRMeta"),
      kpiClientCount: document.getElementById("kpiClientCount"),
      kpiDevCount: document.getElementById("kpiDevCount"),
      workspace: document.querySelector(".workspace"),
      btnOrbitExpand: document.getElementById("btnOrbitExpand"),
      clientDev: document.getElementById("clientDev"),
      swarmGrid: document.getElementById("swarmGrid"),
      swarmEmpty: document.getElementById("swarmEmpty"),
      cashChartWrap: document.getElementById("cashChartWrap"),
      chartBars: document.getElementById("chartBars"),
      chartAxis: document.getElementById("chartAxis"),
      chartPeriodTotal: document.getElementById("chartPeriodTotal"),
      nextBilling: document.getElementById("nextBilling"),
      nextBillingName: document.getElementById("nextBillingName"),
      nextBillingAmount: document.getElementById("nextBillingAmount"),
      nextBillingWhen: document.getElementById("nextBillingWhen"),
      requestList: document.getElementById("requestList"),
      requestsEmpty: document.getElementById("requestsEmpty"),
      tooltip: document.getElementById("nodeTooltip"),
      clientModal: document.getElementById("clientModal"),
      requestModal: document.getElementById("requestModal"),
      clientForm: document.getElementById("clientForm"),
      clientModalTitle: document.getElementById("clientModalTitle"),
      clientSubmitBtn: document.getElementById("clientSubmitBtn"),
      btnDeleteClient: document.getElementById("btnDeleteClient"),
      clientIdField: document.getElementById("clientIdField"),
      clientName: document.getElementById("clientName"),
      clientValue: document.getElementById("clientValue"),
      clientValueLabel: document.getElementById("clientValueLabel"),
      clientValueHint: document.getElementById("clientValueHint"),
      planAnual: document.getElementById("planAnual"),
      planMensual: document.getElementById("planMensual"),
      requestForm: document.getElementById("requestForm"),
      requestClientSelect: document.getElementById("requestClientSelect"),
      clientBillingDate: document.getElementById("clientBillingDate"),
      clientPhone: document.getElementById("clientPhone"),
      contactActions: document.getElementById("contactActions"),
      linkWhatsApp: document.getElementById("linkWhatsApp"),
      linkCall: document.getElementById("linkCall"),
      orbitView: document.getElementById("orbitView"),
      orbitWeb: document.getElementById("orbitWeb"),
      orbitNodes: document.getElementById("orbitNodes"),
      orbitCoreValue: document.getElementById("orbitCoreValue"),
      btnViewOrbit: document.getElementById("btnViewOrbit"),
      btnViewList: document.getElementById("btnViewList"),
      toast: document.getElementById("toast"),
    };
  }

  async init() {
    this.closeModal("clientModal");
    this.closeModal("requestModal");
    this.dom.btnViewOrbit.classList.toggle("is-active", this.view === "orbit");
    this.dom.btnViewList.classList.toggle("is-active", this.view === "list");
    this.applyExpanded();
    this.bindEvents();

    if (this.clients.some((c) => !c.nextBillingDate)) {
      await this.persistState();
    }

    this.renderAll();
    this.startTimerLoop();
  }

  async persistState() {
    if (this.isSaving) return;
    this.isSaving = true;
    try {
      await Store.save(this.clients, this.requests);
    } catch (err) {
      this.showToast(err.message || "Error al guardar en el servidor");
      throw err;
    } finally {
      this.isSaving = false;
    }
  }

  /* ---------- Eventos ---------- */
  bindEvents() {
    document.getElementById("btnOpenClientModal")
      .addEventListener("click", () => this.openCreateClient());

    document.getElementById("btnOpenRequestModal")
      .addEventListener("click", () => this.openRequestModal());

    // Cierre de modales: botón ✕ o clic en el backdrop.
    document.querySelectorAll("[data-close]").forEach((btn) =>
      btn.addEventListener("click", () => this.closeModal(btn.dataset.close))
    );
    [this.dom.clientModal, this.dom.requestModal].forEach((backdrop) =>
      backdrop.addEventListener("click", (e) => {
        if (e.target === backdrop) this.closeModal(backdrop.id);
      })
    );
    document.addEventListener("keydown", (e) => {
      if (e.key === "Escape") {
        this.closeModal("clientModal");
        this.closeModal("requestModal");
      }
    });

    this.dom.clientForm.addEventListener("submit", (e) => this.handleClientSubmit(e));
    this.dom.btnDeleteClient.addEventListener("click", () => this.deleteClient(this.editingClientId));
    this.dom.clientForm.addEventListener("change", (e) => {
      if (e.target.name === "planType") this.updateValueFieldHint();
    });
    this.dom.requestForm.addEventListener("submit", (e) => this.handleAddRequest(e));

    // Delegación: tooltip y highlight en timeline del enjambre.
    this.dom.swarmGrid.addEventListener("mouseover", (e) => this.handleNodeHover(e));
    this.dom.swarmGrid.addEventListener("mouseout", (e) => {
      const inside = e.target.closest(".node, .timeline-row");
      const stillInside = e.relatedTarget?.closest?.("#swarmGrid");
      if (inside && !stillInside) {
        this.hideTooltip();
        this.setChartHighlight(null);
      }
    });
    this.dom.swarmGrid.addEventListener("mousemove", (e) => this.moveTooltip(e));

    this.dom.chartBars.addEventListener("mouseover", (e) => {
      const bar = e.target.closest(".chart-bar");
      if (!bar) return;
      this.setChartHighlight(bar.dataset.date);
    });
    this.dom.chartBars.addEventListener("mouseleave", () => this.setChartHighlight(null));

    // Toggle de vistas Órbita / Lista.
    this.dom.btnViewOrbit.addEventListener("click", () => this.setView("orbit"));
    this.dom.btnViewList.addEventListener("click", () => this.setView("list"));
    this.dom.btnOrbitExpand.addEventListener("click", () => this.toggleExpanded());

    // Órbita: tooltip por hover, edición por clic.
    this.dom.orbitNodes.addEventListener("mouseover", (e) => this.handleNodeHover(e));
    this.dom.orbitNodes.addEventListener("mousemove", (e) => this.moveTooltip(e));
    this.dom.orbitView.addEventListener("mouseleave", () => {
      this.hideTooltip();
      this.setChartHighlight(null);
    });
    this.dom.orbitNodes.addEventListener("click", (e) => {
      const node = e.target.closest(".orbit-node");
      if (node) {
        this.hideTooltip();
        this.openEditClient(node.dataset.id);
      }
    });

    // Actualiza los enlaces de contacto mientras se escribe el teléfono.
    this.dom.clientPhone.addEventListener("input", () => this.refreshContactLinks());

    this.dom.swarmGrid.addEventListener("click", (e) => {
      const paidBtn = e.target.closest("[data-mark-paid]");
      const liveBtn = e.target.closest("[data-mark-live]");
      const editBtn = e.target.closest("[data-edit-client]");
      const deleteBtn = e.target.closest("[data-delete-client]");
      if (liveBtn) {
        e.stopPropagation();
        this.markClientLive(liveBtn.dataset.markLive);
      } else if (paidBtn) {
        e.stopPropagation();
        this.markClientPaid(paidBtn.dataset.markPaid);
      } else if (editBtn) {
        e.stopPropagation();
        this.openEditClient(editBtn.dataset.editClient);
      } else if (deleteBtn) {
        e.stopPropagation();
        this.deleteClient(deleteBtn.dataset.deleteClient);
      }
    });

    // Delegación para completar solicitudes.
    this.dom.requestList.addEventListener("click", (e) => {
      const btn = e.target.closest("[data-complete]");
      if (btn) this.completeRequest(btn.dataset.complete);
    });
  }

  /* ---------- Modales ---------- */
  setDefaultBillingDate() {
    const d = new Date();
    d.setDate(d.getDate() + 7);
    this.dom.clientBillingDate.value = BillingModule.toISODate(d);
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

  updateValueFieldHint() {
    const isAnnual = this.dom.planAnual.checked;
    const isMonthly = this.dom.planMensual.checked;

    if (isAnnual) {
      this.dom.clientValueLabel.textContent = "Valor del contrato anual (CLP)";
      this.dom.clientValueHint.textContent =
        "Ingresa lo que cobras una vez al año. En MRR se divide automáticamente entre 12.";
      this.dom.clientValue.placeholder = "Ej: 1200000";
    } else if (isMonthly) {
      this.dom.clientValueLabel.textContent = "Valor mensual (CLP)";
      this.dom.clientValueHint.textContent =
        "Ingresa lo que cobras cada mes. Ese monto suma directo al MRR.";
      this.dom.clientValue.placeholder = "Ej: 250000";
    } else {
      this.dom.clientValueLabel.textContent = "Valor del plan (CLP)";
      this.dom.clientValueHint.textContent = "Selecciona el tipo de plan para ver qué monto ingresar.";
      this.dom.clientValue.placeholder = "Ej: 250000";
    }
  }

  openCreateClient() {
    this.editingClientId = null;
    this.dom.clientForm.reset();
    this.dom.clientIdField.value = "";
    this.dom.clientModalTitle.textContent = "Nuevo Cliente";
    this.dom.clientSubmitBtn.textContent = "Agregar al Enjambre";
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
    this.dom.clientDev.checked = Boolean(client.inDevelopment);
    (client.planType === "anual" ? this.dom.planAnual : this.dom.planMensual).checked = true;

    this.dom.clientModalTitle.textContent = "Editar Cliente";
    this.dom.clientSubmitBtn.textContent = "Guardar cambios";
    this.dom.btnDeleteClient.hidden = false;
    this.updateValueFieldHint();
    this.refreshContactLinks();
    this.openModal("clientModal");
  }

  /** Muestra WhatsApp/Llamar en el modal cuando hay teléfono válido. */
  refreshContactLinks() {
    const phone = this.dom.clientPhone.value;
    const name = this.dom.clientName.value.trim() || "!";
    const wa = ContactModule.waLink(phone, name);
    const tel = ContactModule.telLink(phone);

    this.dom.contactActions.hidden = !wa;
    if (wa) {
      this.dom.linkWhatsApp.href = wa;
      this.dom.linkCall.href = tel;
    }
  }

  setView(view) {
    this.view = view;
    localStorage.setItem(Store.KEYS.view, view);
    this.dom.btnViewOrbit.classList.toggle("is-active", view === "orbit");
    this.dom.btnViewList.classList.toggle("is-active", view === "list");
    this.renderSwarm();
  }

  applyExpanded() {
    this.dom.workspace.classList.toggle("is-expanded", this.expanded);
    this.dom.btnOrbitExpand.setAttribute("aria-pressed", String(this.expanded));
    this.dom.btnOrbitExpand.textContent = this.expanded ? "⤡" : "⤢";
    this.dom.btnOrbitExpand.title = this.expanded ? "Reducir la órbita" : "Expandir la órbita";
  }

  toggleExpanded() {
    this.expanded = !this.expanded;
    localStorage.setItem(Store.KEYS.expanded, this.expanded ? "1" : "0");
    // Al expandir, la órbita es la protagonista: aseguramos esa vista.
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

  openRequestModal() {
    if (this.clients.length === 0) {
      this.openCreateClient();
      return;
    }
    this.populateClientSelect();
    this.openModal("requestModal");
  }

  populateClientSelect() {
    const select = this.dom.requestClientSelect;
    select.innerHTML = '<option value="" disabled selected>Selecciona un cliente…</option>';
    this.clients.forEach((c) => {
      const opt = document.createElement("option");
      opt.value = c.id;
      opt.textContent = `${c.name} (${c.planType})`;
      select.appendChild(opt);
    });
  }

  /* ---------- Clientes ---------- */
  async handleClientSubmit(e) {
    e.preventDefault();
    const data = new FormData(this.dom.clientForm);

    const payload = {
      name: data.get("name").trim(),
      planType: data.get("planType"),
      valueCLP: Number(data.get("valueCLP")),
      nextBillingDate: data.get("nextBillingDate"),
      phone: data.get("phone").trim(),
      inDevelopment: data.get("inDevelopment") === "on",
    };

    if (!payload.name || !payload.planType || payload.valueCLP <= 0 || !payload.nextBillingDate) return;

    if (this.editingClientId) {
      const idx = this.clients.findIndex((c) => c.id === this.editingClientId);
      if (idx === -1) return;
      this.clients[idx] = { ...this.clients[idx], ...payload };
    } else {
      this.clients.push({ id: crypto.randomUUID(), ...payload });
    }

    try {
      await this.persistState();
      this.showToast(
        this.editingClientId ? `${payload.name} actualizado` : `${payload.name} agregado al enjambre`
      );
      this.dom.clientForm.reset();
      this.editingClientId = null;
      this.closeModal("clientModal");
      this.renderAll();
    } catch {
      /* persistState ya mostró el toast de error */
    }
  }

  async markClientPaid(id) {
    const client = this.clients.find((c) => c.id === id);
    if (!client) return;

    client.nextBillingDate = BillingModule.advanceBillingDate(client);
    try {
      await this.persistState();
      this.renderAll();
      this.showToast(
        `Cobrado · ${client.name} → próximo: ${BillingModule.formatShort(client.nextBillingDate)}`
      );
    } catch {
      /* error ya notificado */
    }
  }

  async markClientLive(id) {
    const client = this.clients.find((c) => c.id === id);
    if (!client) return;

    client.inDevelopment = false;
    try {
      await this.persistState();
      this.renderAll();
      this.showToast(`${client.name} activado · ya suma a tus ingresos`);
    } catch {
      client.inDevelopment = true;
    }
  }

  async deleteClient(id) {
    if (!id) return;
    const client = this.clients.find((c) => c.id === id);
    if (!client) return;

    const activeRequests = this.requests.filter(
      (r) => r.clientId === id && r.status === "activa"
    ).length;

    const msg = activeRequests
      ? `¿Eliminar a "${client.name}"? Se borrarán también ${activeRequests} solicitud${activeRequests === 1 ? "" : "es"} activa${activeRequests === 1 ? "" : "s"}.`
      : `¿Eliminar a "${client.name}" del enjambre?`;

    if (!confirm(msg)) return;

    this.clients = this.clients.filter((c) => c.id !== id);
    this.requests = this.requests.filter((r) => r.clientId !== id);

    try {
      await this.persistState();
      this.editingClientId = null;
      this.closeModal("clientModal");
      this.renderAll();
      this.showToast(`${client.name} eliminado`);
    } catch {
      /* error ya notificado */
    }
  }

  /* ---------- Solicitudes ---------- */
  async handleAddRequest(e) {
    e.preventDefault();
    const data = new FormData(this.dom.requestForm);

    const request = {
      id: crypto.randomUUID(),
      clientId: data.get("clientId"),
      description: data.get("description").trim(),
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
    } catch {
      this.requests = prev;
    }
  }

  /* ---------- Cronómetros ---------- */
  startTimerLoop() {
    // Un único intervalo global actualiza todos los cronómetros a la vez;
    // más barato que un setInterval por solicitud.
    this.timerId = setInterval(() => this.updateTimers(), TIMER_TICK_MS);
  }

  updateTimers() {
    this.dom.requestList.querySelectorAll(".timer").forEach((el) => {
      const createdAt = Number(el.dataset.createdAt);
      const { label, overdue } = CRMController.elapsedInfo(createdAt);
      el.textContent = label;
      el.classList.toggle("is-overdue", overdue);
    });
  }

  static elapsedInfo(createdAt) {
    const elapsedMs = Date.now() - createdAt;
    const totalMinutes = Math.floor(elapsedMs / 60000);
    const hours = Math.floor(totalMinutes / 60);
    const minutes = totalMinutes % 60;
    return {
      label: `${hours}h ${String(minutes).padStart(2, "0")}m`,
      overdue: hours >= OVERDUE_HOURS,
    };
  }

  /* ---------- Tooltip del enjambre ---------- */
  handleNodeHover(e) {
    const el = e.target.closest(".node, .timeline-row, .orbit-node");
    if (!el) return;

    const id = el.dataset.id;
    const client = this.clients.find((c) => c.id === id);
    if (!client) return;

    const days = BillingModule.daysUntil(client.nextBillingDate);
    const tip = this.dom.tooltip;
    tip.querySelector(".node-tooltip__name").textContent = client.name;
    tip.querySelector(".node-tooltip__plan").textContent = client.inDevelopment
      ? `Plan ${client.planType} · En desarrollo`
      : `Plan ${client.planType}`;
    tip.querySelector(".node-tooltip__date").textContent = client.inDevelopment
      ? `Lanzamiento estimado · ${BillingModule.formatLong(client.nextBillingDate)}`
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

  /* ---------- Render ---------- */
  renderAll() {
    this.renderKPIs();
    this.renderNextBilling();
    this.renderChart();
    this.renderSwarm();
    this.renderRequests();
  }

  renderKPIs() {
    const metrics = FinanceModule.computeMetrics(this.clients);
    const { portfolio, mrr, arr, monthlyMRR, annualMRR, monthlySum, annualSum } = metrics;

    const updates = [
      [this.dom.kpiPortfolio, portfolio],
      [this.dom.kpiMRR, mrr],
      [this.dom.kpiARR, arr],
    ];

    updates.forEach(([el, value]) => {
      el.textContent = FinanceModule.formatCLP(value);
      el.classList.remove("is-updated");
      void el.offsetWidth;
      el.classList.add("is-updated");
    });

    const parts = [];
    if (monthlySum > 0) parts.push(`mensuales ${FinanceModule.formatCLP(monthlySum)}`);
    if (annualSum > 0) parts.push(`anuales ${FinanceModule.formatCLP(annualSum)}`);
    this.dom.kpiPortfolioMeta.textContent = parts.length
      ? parts.join(" + ")
      : "Suma nominal de planes";

    const mrrParts = [];
    if (monthlyMRR > 0) mrrParts.push(`mensuales ${FinanceModule.formatCLP(monthlyMRR)}`);
    if (annualMRR > 0) mrrParts.push(`anuales ÷12 ${FinanceModule.formatCLP(annualMRR)}`);
    this.dom.kpiMRRMeta.textContent = mrrParts.length
      ? mrrParts.join(" + ")
      : "mensuales + anuales ÷ 12";

    const arrParts = [];
    if (annualSum > 0) arrParts.push(`anuales ${FinanceModule.formatCLP(annualSum)}`);
    if (monthlySum > 0) arrParts.push(`mensuales ×12 ${FinanceModule.formatCLP(monthlySum * 12)}`);
    this.dom.kpiARRMeta.textContent = arrParts.length
      ? arrParts.join(" + ")
      : "anuales + mensuales × 12";

    const n = this.clients.length - metrics.devCount;
    this.dom.kpiClientCount.textContent = `${n} cliente${n === 1 ? "" : "s"} activo${n === 1 ? "" : "s"}`;

    if (metrics.devCount > 0) {
      this.dom.kpiDevCount.hidden = false;
      this.dom.kpiDevCount.textContent =
        `${metrics.devCount} en desarrollo · pipeline ${FinanceModule.formatCLP(metrics.devPipeline)}/mes`;
    } else {
      this.dom.kpiDevCount.hidden = true;
    }
  }

  /** Clientes que ya facturan (excluye los que están en desarrollo). */
  activeClients() {
    return this.clients.filter((c) => !c.inDevelopment);
  }

  renderNextBilling() {
    const billing = this.activeClients();
    const next = BillingModule.nextBillingClient(billing);
    const hasBilling = billing.length > 0;
    this.dom.nextBilling.hidden = !hasBilling;
    this.dom.cashChartWrap.hidden = !hasBilling;

    if (!next) return;

    const days = BillingModule.daysUntil(next.nextBillingDate);
    this.dom.nextBillingName.textContent = next.name;
    this.dom.nextBillingAmount.textContent = FinanceModule.formatCLP(next.valueCLP);
    this.dom.nextBillingWhen.textContent =
      `${BillingModule.formatLong(next.nextBillingDate)} · ${BillingModule.relativeLabel(days)}`;
  }

  renderChart() {
    const series = BillingModule.buildCashFlowSeries(this.activeClients());
    const maxTotal = Math.max(...series.map((b) => b.total), 1);
    const periodTotal = series.reduce((sum, b) => sum + b.total, 0);

    this.dom.chartPeriodTotal.textContent = FinanceModule.formatCLP(periodTotal);
    this.dom.chartBars.innerHTML = "";

    const fragment = document.createDocumentFragment();

    series.forEach((bucket) => {
      const bar = document.createElement("div");
      bar.className = "chart-bar";
      if (bucket.isToday) bar.classList.add("is-today");
      if (bucket.total > 0) bar.style.setProperty("--bar-h", `${(bucket.total / maxTotal) * 100}%`);
      bar.dataset.date = bucket.date;
      bar.setAttribute("role", "presentation");
      bar.title = bucket.total
        ? `${BillingModule.formatShort(bucket.date)}: ${FinanceModule.formatCLP(bucket.total)}`
        : BillingModule.formatShort(bucket.date);

      const tip = document.createElement("span");
      tip.className = "chart-bar__tip";
      tip.textContent = bucket.total
        ? `${FinanceModule.formatCLP(bucket.total)} · ${bucket.clients.length} cliente${bucket.clients.length === 1 ? "" : "s"}`
        : "—";
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
    const showList = this.view === "list" && hasClients;
    this.dom.orbitView.hidden = !showOrbit;
    this.dom.swarmGrid.hidden = !showList;

    if (!hasClients) return;
    if (showOrbit) {
      this.renderOrbit();
      return;
    }
    this.renderList();
  }

  /**
   * Mapa orbital: radio = días hasta el cobro, tamaño = valor del contrato,
   * ángulo = distribución áurea (137.5°) para evitar solapamientos.
   */
  renderOrbit() {
    const container = this.dom.orbitNodes;
    container.innerHTML = "";
    this.dom.orbitWeb.innerHTML = "";

    const { mrr } = FinanceModule.computeMetrics(this.clients);
    this.dom.orbitCoreValue.textContent = FinanceModule.formatCLP(mrr);

    const maxValue = Math.max(...this.clients.map((c) => Number(c.valueCLP) || 0), 1);
    const sorted = BillingModule.sortByBilling(this.clients);
    const fragment = document.createDocumentFragment();

    const GOLDEN_ANGLE = 137.5;

    sorted.forEach((client, i) => {
      const isDev = Boolean(client.inDevelopment);
      const days = BillingModule.daysUntil(client.nextBillingDate);
      // Los clientes en desarrollo no facturan: no heredan urgencia (rojo/ámbar).
      const urgency = isDev ? "normal" : BillingModule.urgency(days);

      // Radio: anillos alineados con las guías visuales (7d/14d/30d/+30d).
      // Los proyectos en desarrollo orbitan en la periferia (aún no cobran).
      // Anillos guía (mitad del eje): 7d→17, 14d→27, 30d→38, +30d→47.
      let radiusPct;
      if (isDev) radiusPct = 42 + (i % 3) * 2;
      else if (days < 0) radiusPct = 12;
      else if (days <= 7) radiusPct = 10 + (days / 7) * 7;
      else if (days <= 14) radiusPct = 17 + ((days - 7) / 7) * 10;
      else if (days <= 30) radiusPct = 27 + ((days - 14) / 16) * 11;
      else radiusPct = Math.min(38 + ((days - 30) / 60) * 8, 46);

      // Radio en cada eje = mismo % → los nodos caen sobre los anillos elípticos,
      // que a su vez replican el aspect ratio del área (nada se sale de la "caja").
      const angleRad = ((i * GOLDEN_ANGLE) % 360) * (Math.PI / 180);
      const x = 50 + radiusPct * Math.cos(angleRad);
      const y = 50 + radiusPct * Math.sin(angleRad);

      // Tamaño: escala por raíz cuadrada para que la diferencia sea legible sin aplastar los pequeños.
      const sizeRatio = Math.sqrt((Number(client.valueCLP) || 0) / maxValue);
      const size = Math.round(44 + sizeRatio * 40);

      const link = document.createElementNS("http://www.w3.org/2000/svg", "line");
      link.setAttribute("x1", "50");
      link.setAttribute("y1", "50");
      link.setAttribute("x2", String(x));
      link.setAttribute("y2", String(y));
      if (isDev) {
        link.classList.add("orbit-link", "orbit-link--dev");
      } else {
        link.classList.add("orbit-link", `orbit-link--${client.planType}`);
        if (urgency !== "normal") link.classList.add(`orbit-link--${urgency}`);
      }
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
      node.style.setProperty("--sat-speed", `${4.2 + (i % 3) * 0.7}s`);
      node.style.setProperty("--float-delay", `${(i % 5) * -1.2}s`);
      node.setAttribute("role", "button");
      node.setAttribute(
        "aria-label",
        isDev
          ? `${client.name}, en desarrollo`
          : `${client.name}, cobro ${BillingModule.relativeLabel(days)}`
      );
      node.title = `${client.name} · clic para editar`;

      const initials = document.createElement("span");
      initials.className = "orbit-node__initials";
      initials.textContent = CRMController.getInitials(client.name);

      const amount = document.createElement("span");
      amount.className = "orbit-node__amount";
      amount.textContent = isDev ? "en dev" : days < 0 ? "¡Vencido!" : `${days}d`;

      node.append(initials, amount);

      if (isDev) {
        const badge = document.createElement("span");
        badge.className = "orbit-node__badge orbit-node__badge--dev";
        badge.textContent = "DEV";
        node.appendChild(badge);
      } else if (client.planType === "mensual") {
        const badge = document.createElement("span");
        badge.className = "orbit-node__badge";
        badge.textContent = "MRR";

        const satA = document.createElement("span");
        satA.className = "orbit-node__satellite orbit-node__satellite--a";
        const satB = document.createElement("span");
        satB.className = "orbit-node__satellite orbit-node__satellite--b";

        node.append(badge, satA, satB);
      }

      fragment.appendChild(node);
    });

    container.appendChild(fragment);
  }

  renderList() {
    const grid = this.dom.swarmGrid;
    grid.innerHTML = "";

    const sorted = BillingModule.sortByBilling(this.clients);
    const fragment = document.createDocumentFragment();

    sorted.forEach((client, i) => {
      const isDev = Boolean(client.inDevelopment);
      const days = BillingModule.daysUntil(client.nextBillingDate);
      const urgency = isDev ? "dev" : BillingModule.urgency(days);
      const dayNum = BillingModule.parseDate(client.nextBillingDate).getDate();

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
      dayLabel.textContent = String(dayNum).padStart(2, "0");

      const initials = document.createElement("span");
      initials.className = "node__initials";
      initials.textContent = CRMController.getInitials(client.name);

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
        tag.style.marginLeft = "8px";
        nameEl.appendChild(tag);
      }

      const countdown = isDev
        ? `<span class="timeline-row__countdown">En desarrollo</span>`
        : `<span class="timeline-row__countdown">${BillingModule.relativeLabel(days)}</span>`;

      const sub = document.createElement("div");
      sub.className = "timeline-row__sub";
      sub.innerHTML = `
        ${countdown}
        <span>Plan ${client.planType}</span>
        <em>${FinanceModule.formatCLP(client.valueCLP)}</em>
      `;

      meta.append(dateEl, nameEl, sub);

      const actions = document.createElement("div");
      actions.className = "timeline-row__actions";

      if (isDev) {
        const liveBtn = document.createElement("button");
        liveBtn.type = "button";
        liveBtn.className = "btn-row btn-row--live";
        liveBtn.dataset.markLive = client.id;
        liveBtn.textContent = "🚀 Activar";
        actions.appendChild(liveBtn);
      } else {
        const paidBtn = document.createElement("button");
        paidBtn.type = "button";
        paidBtn.className = "btn-row btn-row--paid";
        paidBtn.dataset.markPaid = client.id;
        paidBtn.textContent = "✓ Cobrado";
        actions.appendChild(paidBtn);
      }

      const waHref = ContactModule.waLink(client.phone, client.name);
      if (waHref) {
        const waLink = document.createElement("a");
        waLink.className = "btn-row btn-row--wa";
        waLink.href = waHref;
        waLink.target = "_blank";
        waLink.rel = "noopener";
        waLink.textContent = "WhatsApp";
        actions.appendChild(waLink);

        const callLink = document.createElement("a");
        callLink.className = "btn-row btn-row--call";
        callLink.href = ContactModule.telLink(client.phone);
        callLink.textContent = "Llamar";
        actions.appendChild(callLink);
      }

      const editBtn = document.createElement("button");
      editBtn.type = "button";
      editBtn.className = "btn-row btn-row--edit";
      editBtn.dataset.editClient = client.id;
      editBtn.textContent = "Editar";

      const deleteBtn = document.createElement("button");
      deleteBtn.type = "button";
      deleteBtn.className = "btn-row btn-row--delete";
      deleteBtn.dataset.deleteClient = client.id;
      deleteBtn.textContent = "Eliminar";

      actions.append(editBtn, deleteBtn);
      row.append(rail, node, meta, actions);
      fragment.appendChild(row);
    });

    grid.appendChild(fragment);

    if (this.highlightDate) this.setChartHighlight(this.highlightDate);
  }

  static getInitials(name) {
    return name
      .split(/\s+/)
      .slice(0, 2)
      .map((w) => w[0]?.toUpperCase() ?? "")
      .join("");
  }

  renderRequests() {
    const list = this.dom.requestList;
    list.innerHTML = "";

    const active = this.requests.filter((r) => r.status === "activa");
    this.dom.requestsEmpty.hidden = active.length > 0;

    const fragment = document.createDocumentFragment();

    // Más recientes primero.
    [...active].sort((a, b) => b.createdAt - a.createdAt).forEach((req) => {
      const client = this.clients.find((c) => c.id === req.clientId);
      const { label, overdue } = CRMController.elapsedInfo(req.createdAt);

      const li = document.createElement("li");
      li.className = "request-card";
      if (client?.planType === "anual") li.classList.add("request-card--annual-client");

      const top = document.createElement("div");
      top.className = "request-card__top";
      const clientName = document.createElement("span");
      clientName.className = "request-card__client";
      clientName.textContent = client?.name ?? "Cliente eliminado";
      top.appendChild(clientName);

      const desc = document.createElement("p");
      desc.className = "request-card__desc";
      desc.textContent = req.description;

      const bottom = document.createElement("div");
      bottom.className = "request-card__bottom";

      const timer = document.createElement("span");
      timer.className = `timer${overdue ? " is-overdue" : ""}`;
      timer.dataset.createdAt = req.createdAt;
      timer.textContent = label;

      const doneBtn = document.createElement("button");
      doneBtn.type = "button";
      doneBtn.className = "btn-done";
      doneBtn.dataset.complete = req.id;
      doneBtn.textContent = "Completar";

      bottom.append(timer, doneBtn);
      li.append(top, desc, bottom);
      fragment.appendChild(li);
    });

    list.appendChild(fragment);
  }
}

/* ------------------------------------------------------------
   Bootstrap
   ------------------------------------------------------------ */
document.addEventListener("DOMContentLoaded", async () => {
  const boot = document.getElementById("bootScreen");
  const bootError = document.getElementById("bootError");

  try {
    const data = await Store.init();
    boot.hidden = true;
    const app = new CRMController(data.clients, data.requests);
    await app.init();
  } catch (err) {
    boot.hidden = true;
    bootError.hidden = false;
    bootError.querySelector("p").textContent =
      err.message || "No se pudo conectar con el servidor. Verifica que PHP esté activo y la carpeta /data tenga permisos de escritura.";
  }
});
