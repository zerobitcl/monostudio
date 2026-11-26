/* Drawer del sidebar bajo 1024px. No toca módulos, formularios ni app.js. */
(function () {
  var shell = document.getElementById("appShell");
  var openBtn = document.getElementById("btnSidebarOpen");
  var closeBtn = document.getElementById("btnSidebarClose");
  var backdrop = document.getElementById("sidebarBackdrop");
  if (!shell || !openBtn) return;

  function setOpen(on) {
    shell.classList.toggle("is-sidebar-open", on);
    if (backdrop) backdrop.hidden = !on;
    openBtn.setAttribute("aria-expanded", on ? "true" : "false");
  }

  openBtn.addEventListener("click", function () {
    setOpen(true);
  });
  if (closeBtn) {
    closeBtn.addEventListener("click", function () {
      setOpen(false);
    });
  }
  if (backdrop) {
    backdrop.addEventListener("click", function () {
      setOpen(false);
    });
  }
  shell.querySelectorAll(".module-nav__btn").forEach(function (btn) {
    btn.addEventListener("click", function () {
      if (window.matchMedia("(max-width: 1023px)").matches) setOpen(false);
    });
  });
  document.addEventListener("keydown", function (event) {
    if (event.key === "Escape" && shell.classList.contains("is-sidebar-open")) setOpen(false);
  });
})();
