(function () {
  const form = document.getElementById("survey");
  const rutInput = document.getElementById("rut");
  const nombreInput = document.getElementById("nombre");
  const texto = document.getElementById("texto");
  const count = document.getElementById("count");
  const note = document.getElementById("note");
  const state = document.getElementById("state");
  const stateTitle = document.getElementById("stateTitle");
  const stateBody = document.getElementById("stateBody");
  const saveBtn = document.getElementById("save");
  const memoryKey = "encuesta-tribunal-rut";
  let hydrating = false;
  let timer = null;

  function paintCount() {
    const n = texto.value.length;
    count.textContent = n.toLocaleString("es-CL") + (n === 1 ? " caracter" : " caracteres");
    texto.style.height = "auto";
    texto.style.height = Math.max(texto.scrollHeight, 220) + "px";
  }

  function showNote(text) {
    note.hidden = !text;
    note.textContent = text || "";
  }

  function showState(mode, title, body, celebrate) {
    if (!title) {
      state.hidden = true;
      return;
    }
    state.hidden = false;
    state.className = "state is-" + mode;
    void state.offsetWidth;
    if (celebrate) state.classList.add("is-saved");
    stateTitle.textContent = title;
    stateBody.textContent = body || "";
    if (celebrate) {
      state.scrollIntoView({ block: "nearest", behavior: "smooth" });
    }
  }

  function rutBody(value) {
    return String(value || "").toUpperCase().replace(/[^0-9K]/g, "");
  }

  function validRut(value) {
    const clean = rutBody(value);
    if (clean.length < 8 || clean.length > 9) return "";
    const dv = clean.slice(-1);
    const num = clean.slice(0, -1);
    let sum = 0;
    let mul = 2;
    for (let i = num.length - 1; i >= 0; i--) {
      sum += Number(num[i]) * mul;
      mul = mul === 7 ? 2 : mul + 1;
    }
    const rest = 11 - (sum % 11);
    const expected = rest === 11 ? "0" : rest === 10 ? "K" : String(rest);
    return dv === expected ? num + "-" + dv : "";
  }

  async function post(action) {
    const res = await fetch("submit.php", {
      method: "POST",
      headers: { "Content-Type": "application/json", Accept: "application/json" },
      body: JSON.stringify({
        action: action,
        rut: rutInput.value.trim(),
        nombre: nombreInput.value.trim(),
        texto: texto.value.trim(),
        empresa: form.empresa.value,
      }),
    });
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok || data.error) throw new Error(data.error || "No se pudo guardar");
    return data;
  }

  async function loadSession() {
    const rut = validRut(rutInput.value);
    if (!rut) return;
    rutInput.value = rut;
    try {
      const data = await post("load");
      localStorage.setItem(memoryKey, rut);
      if (!data.found) {
        showState("info", "Sesión nueva", "Guarda cuando quieras y sigue después con este mismo RUT.");
        return;
      }
      hydrating = true;
      nombreInput.value = data.nombre || "";
      texto.value = data.texto || "";
      paintCount();
      hydrating = false;
      showState("info", "Texto recuperado", "Puedes agregar, quitar y volver a guardar.");
      showNote("");
    } catch (err) {
      showNote(err.message || "No se pudo abrir la sesión.");
    }
  }

  async function persist(action, quiet) {
    if (!quiet) showNote("");
    const rut = validRut(rutInput.value);
    if (!rut) {
      if (!quiet) showNote("El RUT no es válido. Escríbelo con guion, por ejemplo 12.345.678-9.");
      return;
    }
    rutInput.value = rut;
    const nombre = nombreInput.value.trim();
    const body = texto.value.trim();
    if (nombre.length < 2) {
      if (!quiet) showNote("Escribe tu nombre antes de guardar.");
      return;
    }
    if (!quiet) {
      saveBtn.disabled = true;
      saveBtn.textContent = "Guardando…";
    }
    try {
      await post("save");
      localStorage.setItem(memoryKey, rut);
      const empty = body.length < 1;
      showState(quiet ? "quiet" : "saved", empty ? "Texto borrado" : "Guardado", empty
        ? "Guardamos el cuadro vacío. Puedes escribir de nuevo con este RUT."
        : "Puedes cerrar y seguir después con tu RUT: agregar, quitar y volver a guardar.", !quiet);
      if (!quiet) showNote("");
    } catch (err) {
      showNote(err.message || "No se pudo guardar. Intenta de nuevo.");
    } finally {
      if (!quiet) {
        saveBtn.disabled = false;
        saveBtn.textContent = "Guardar";
      }
    }
  }

  function queueSave() {
    if (hydrating) return;
    clearTimeout(timer);
    timer = setTimeout(function () {
      if (validRut(rutInput.value) && nombreInput.value.trim().length >= 2 && texto.value.trim().length >= 1) {
        persist("save", true);
      }
    }, 2500);
  }

  texto.addEventListener("input", function () {
    paintCount();
    queueSave();
  });
  nombreInput.addEventListener("input", queueSave);
  rutInput.addEventListener("change", loadSession);
  form.addEventListener("submit", function (event) {
    event.preventDefault();
    clearTimeout(timer);
    persist("save");
  });

  paintCount();
  const remembered = localStorage.getItem(memoryKey) || "";
  if (remembered) {
    rutInput.value = remembered;
    loadSession();
  }
})();
