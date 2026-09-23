/* =========================================================================
   MONO STUDIO — main.js
   Vanilla JS, cero dependencias, defer-loaded.
   ========================================================================= */
(() => {
  "use strict";

  const header = document.querySelector(".site-header");
  if (header) {
    const onScroll = () => header.classList.toggle("scrolled", window.scrollY > 24);
    onScroll();
    window.addEventListener("scroll", onScroll, { passive: true });
  }

  const nav = document.querySelector(".nav");
  const toggle = document.querySelector(".nav__toggle");
  if (nav && toggle) {
    const setOpen = (open) => {
      nav.classList.toggle("open", open);
      toggle.setAttribute("aria-expanded", String(open));
    };
    toggle.addEventListener("click", () => setOpen(!nav.classList.contains("open")));
    nav.querySelectorAll(".nav__links a").forEach((a) => a.addEventListener("click", () => setOpen(false)));
  }

  document.querySelectorAll("[data-year]").forEach((el) => (el.textContent = new Date().getFullYear()));

  const revealEls = document.querySelectorAll("[data-reveal], [data-reveal-stagger]");
  const reduced = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
  if (reduced || !("IntersectionObserver" in window)) {
    revealEls.forEach((el) => el.classList.add("in"));
  } else {
    const io = new IntersectionObserver(
      (entries) => {
        entries.forEach((entry) => {
          if (!entry.isIntersecting) return;
          const el = entry.target;
          if (el.hasAttribute("data-reveal-stagger")) {
            Array.from(el.children).forEach((child, i) => (child.style.transitionDelay = `${Math.min(i, 8) * 70}ms`));
          }
          el.classList.add("in");
          io.unobserve(el);
        });
      },
      { threshold: 0.12, rootMargin: "0px 0px -6% 0px" }
    );
    revealEls.forEach((el) => io.observe(el));
  }

  const typer = document.querySelector("[data-typer]");
  if (typer && !reduced) {
    const queries = typer.dataset.typer.split("|");
    let q = 0;
    let i = queries[0].length;
    let deleting = true;
    let visible = true;
    // Pausado fuera de pantalla: evita trabajo del hilo principal que no ve nadie
    if ("IntersectionObserver" in window) {
      new IntersectionObserver(([e]) => (visible = e.isIntersecting)).observe(typer);
    }
    typer.textContent = queries[0];
    const tick = () => {
      let delay = 55;
      if (visible) {
        if (deleting) {
          i--;
          delay = 28;
          if (i === 0) { deleting = false; q = (q + 1) % queries.length; delay = 350; }
        } else {
          i++;
          if (i === queries[q].length) { deleting = true; delay = 2200; }
        }
        typer.textContent = queries[q].slice(0, i);
      }
      setTimeout(tick, delay);
    };
    setTimeout(tick, 2200);
  }

  const faqItems = document.querySelectorAll(".faq-item");
  faqItems.forEach((item) =>
    item.addEventListener("toggle", () => {
      if (item.open) faqItems.forEach((other) => other !== item && (other.open = false));
    })
  );
})();
