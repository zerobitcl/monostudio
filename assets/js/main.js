/* =========================================================================
   MONO STUDIO — main.js
   Vanilla JS. IntersectionObserver para no bloquear el hilo principal.
   Cero dependencias. Defer-loaded.
   ========================================================================= */
(() => {
  "use strict";

  const prefersReduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;

  /* ---------- Header: estado scrolled ---------- */
  const header = document.querySelector(".site-header");
  if (header) {
    const onScroll = () => header.classList.toggle("scrolled", window.scrollY > 24);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  /* ---------- Nav móvil ---------- */
  const nav = document.querySelector(".nav");
  const toggle = document.querySelector(".nav__toggle");
  if (nav && toggle) {
    toggle.addEventListener("click", () => {
      const open = nav.classList.toggle("open");
      toggle.setAttribute("aria-expanded", String(open));
    });
    nav.querySelectorAll(".nav__links a").forEach((a) =>
      a.addEventListener("click", () => nav.classList.remove("open"))
    );
  }

  /* ---------- Año dinámico en footer ---------- */
  document.querySelectorAll("[data-year]").forEach((el) => (el.textContent = new Date().getFullYear()));

  /* ---------- Scroll reveal (un solo observer, desconecta al revelar) ---------- */
  const revealEls = document.querySelectorAll("[data-reveal], [data-reveal-stagger], .reveal-words");
  if (revealEls.length) {
    if (prefersReduced || !("IntersectionObserver" in window)) {
      revealEls.forEach((el) => el.classList.add("in"));
    } else {
      const io = new IntersectionObserver(
        (entries, obs) => {
          entries.forEach((entry) => {
            if (!entry.isIntersecting) return;
            const el = entry.target;

            // Stagger: aplica retraso incremental a los hijos.
            if (el.hasAttribute("data-reveal-stagger")) {
              Array.from(el.children).forEach((child, i) => {
                child.style.transitionDelay = `${i * 80}ms`;
              });
            }
            // Texto palabra por palabra
            if (el.classList.contains("reveal-words")) {
              el.querySelectorAll(".word").forEach((w, i) => {
                w.style.transitionDelay = `${i * 45}ms`;
              });
            }
            el.classList.add("in");
            obs.unobserve(el);
          });
        },
        { threshold: 0.16, rootMargin: "0px 0px -8% 0px" }
      );
      revealEls.forEach((el) => io.observe(el));
    }
  }

  /* ---------- Texto: envolver palabras para revelado escalonado ---------- */
  document.querySelectorAll("[data-split-words]").forEach((el) => {
    const text = el.textContent.trim();
    el.classList.add("reveal-words");
    el.setAttribute("aria-label", text);
    el.innerHTML = text
      .split(/\s+/)
      .map((w) => `<span class="word" aria-hidden="true">${w}</span>`)
      .join(" ");
  });

  /* ---------- Spotlight 3D en tarjetas de servicio ---------- */
  if (!prefersReduced && window.matchMedia("(pointer: fine)").matches) {
    document.querySelectorAll(".svc-card").forEach((card) => {
      let raf = null;
      card.addEventListener("pointermove", (e) => {
        const r = card.getBoundingClientRect();
        const x = e.clientX - r.left;
        const y = e.clientY - r.top;
        if (raf) return;
        raf = requestAnimationFrame(() => {
          card.style.setProperty("--mx", `${x}px`);
          card.style.setProperty("--my", `${y}px`);
          const rx = ((y / r.height) - 0.5) * -6;
          const ry = ((x / r.width) - 0.5) * 6;
          card.style.transform = `translateY(-6px) perspective(900px) rotateX(${rx}deg) rotateY(${ry}deg)`;
          raf = null;
        });
      });
      card.addEventListener("pointerleave", () => {
        card.style.transform = "";
      });
    });
  }

  /* ---------- FAQ: cierra los demás al abrir uno (acordeón) ---------- */
  const faqItems = document.querySelectorAll(".faq-item");
  faqItems.forEach((item) => {
    item.addEventListener("toggle", () => {
      if (item.open) {
        faqItems.forEach((other) => {
          if (other !== item) other.open = false;
        });
      }
    });
  });

  /* =======================================================================
     THE NATIVE FLEX — comparador interactivo de rendimiento
     Modela cómo los plugins/scripts degradan métricas reales.
     ======================================================================= */
  const lab = document.querySelector("[data-flex-lab]");
  if (lab) {
    const pluginSlider = lab.querySelector("#plugins");
    const pluginOut = lab.querySelector("[data-plugins-out]");
    const toggleBtns = lab.querySelectorAll(".lab-toggle button");
    const pill = lab.querySelector(".lab-toggle__pill");
    const ring = lab.querySelector(".score-ring");
    const ringNum = lab.querySelector(".score-ring__num");
    const verdict = lab.querySelector(".lab-score__verdict");

    const metrics = {
      lcp: lab.querySelector('[data-metric="lcp"]'),
      tbt: lab.querySelector('[data-metric="tbt"]'),
      weight: lab.querySelector('[data-metric="weight"]'),
      req: lab.querySelector('[data-metric="req"]'),
    };

    const movePill = (btn) => {
      if (!pill) return;
      pill.style.left = `${btn.offsetLeft}px`;
      pill.style.width = `${btn.offsetWidth}px`;
    };

    const setMetric = (node, value, unit, max, lowerIsBetter = true) => {
      if (!node) return;
      const valEl = node.querySelector(".metric__val");
      const fill = node.querySelector(".metric__fill");
      const tag = node.querySelector(".metric__tag");
      valEl.firstChild.textContent = value;
      const ratio = Math.min(value / max, 1);
      fill.style.width = `${ratio * 100}%`;
      // Verde si está en zona buena
      const good = lowerIsBetter ? ratio < 0.45 : ratio > 0.7;
      fill.style.background = good ? "var(--emerald)" : ratio < 0.7 ? "#f59e0b" : "#f43f5e";
      if (tag) {
        tag.textContent = good ? "Óptimo" : ratio < 0.7 ? "Medio" : "Crítico";
        tag.className = "metric__tag " + (good ? "good" : "bad");
      }
    };

    const compute = () => {
      const mode = lab.dataset.mode; // "native" | "bloated"
      const plugins = parseInt(pluginSlider.value, 10);
      if (pluginOut) pluginOut.textContent = plugins;

      // Coeficiente base según arquitectura.
      const base = mode === "native" ? 1 : 3.4;

      // Modelo simplificado pero realista del costo por plugin.
      const lcp = +(0.6 * base + plugins * (mode === "native" ? 0.05 : 0.22)).toFixed(1);
      const tbt = Math.round(20 * base + plugins * (mode === "native" ? 6 : 48));
      const weight = Math.round(180 * base + plugins * (mode === "native" ? 25 : 140));
      const req = Math.round(12 * base + plugins * (mode === "native" ? 1.5 : 6));

      setMetric(metrics.lcp, lcp, "s", 6);
      setMetric(metrics.tbt, tbt, "ms", 1200);
      setMetric(metrics.weight, weight, "KB", 4000);
      setMetric(metrics.req, req, "", 120);

      // Score Lighthouse aproximado (penaliza LCP y TBT).
      let score = 100 - (lcp - 0.6) * 14 - (tbt / 26) - (weight / 220) - req * 0.25;
      score = Math.max(8, Math.min(100, Math.round(score)));

      if (ring) {
        ring.style.setProperty("--p", score);
        ring.style.setProperty("--ring", score >= 90 ? "var(--emerald)" : score >= 60 ? "#f59e0b" : "#f43f5e");
      }
      if (ringNum) ringNum.textContent = score;
      if (verdict) {
        const isGood = score >= 90;
        verdict.textContent = isGood
          ? "Listo para rankear y convertir."
          : score >= 60
          ? "Aceptable, pero pierde ventas por velocidad."
          : "Penalizado por Google. Fuga de clientes.";
        verdict.className = "lab-score__verdict " + (isGood ? "good" : "bad");
      }
    };

    toggleBtns.forEach((btn) => {
      btn.addEventListener("click", () => {
        toggleBtns.forEach((b) => b.setAttribute("aria-pressed", "false"));
        btn.setAttribute("aria-pressed", "true");
        lab.dataset.mode = btn.dataset.mode;
        movePill(btn);
        compute();
      });
    });

    if (pluginSlider) pluginSlider.addEventListener("input", compute);

    // Init
    const active = lab.querySelector('.lab-toggle button[aria-pressed="true"]') || toggleBtns[0];
    if (active) requestAnimationFrame(() => movePill(active));
    compute();
    window.addEventListener("resize", () => { if (active) movePill(lab.querySelector('.lab-toggle button[aria-pressed="true"]')); }, { passive: true });
  }
})();
