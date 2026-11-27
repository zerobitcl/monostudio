(function () {
  const form = document.getElementById("survey");
  const rutInput = document.getElementById("rut");
  const nombreInput = document.getElementById("nombre");
  const texto = document.getElementById("texto");
  const count = document.getElementById("count");
  const note = document.getElementById("note");
  const state = document.getElementById("state");
  const saveBtn = document.getElementById("save");
  const send = document.getElementById("send");
  const memoryKey = "encuesta-tribunal-rut";
  let hydrating = false;
  let timer = null;

  function paintCount() {
    count.textContent = texto.value.length + " / 8000";
    texto.style.height = "auto";
    texto.style.height = Math.max(texto.scrollHeight, 220) + "px";
  }

  function showNote(text) {
    note.hidden = !text;
    note.textContent = text || "";
  }

  function showState(text) {
    state.hidden = !text;
    state.textContent = text || "";
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
        showState("RUT nuevo. Lo que escribas puedes guardarlo y retomarlo con este mismo RUT.");
        return;
      }
      hydrating = true;
      nombreInput.value = data.nombre || "";
      texto.value = data.texto || "";
      paintCount();
      hydrating = false;
      showState(data.status === "enviado"
        ? "Ya habías enviado este registro. Puedes editarlo y volver a guardar."
        : "Retomamos tu borrador.");
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
    if (nombre.length < 2 || (action === "send" ? body.length < 10 : body.length < 1)) {
      if (!quiet) {
        showNote(action === "send"
          ? "Escribe tu nombre y al menos un párrafo breve."
          : "Escribe tu nombre y algo de texto antes de guardar.");
      }
      return;
    }
    if (!quiet) {
      saveBtn.disabled = true;
      send.disabled = true;
      send.textContent = action === "send" ? "Enviando…" : "Enviar registro";
      saveBtn.textContent = action === "save" ? "Guardando…" : "Guardar";
    }
    try {
      const data = await post(action);
      localStorage.setItem(memoryKey, rut);
      showState(action === "send"
        ? "Registro enviado. Puedes volver a entrar con tu RUT."
        : data.status === "enviado"
          ? "Cambios guardados."
          : "Borrador guardado en este RUT.");
      if (!quiet) showNote("");
    } catch (err) {
      showNote(err.message || "No se pudo guardar. Intenta de nuevo.");
    } finally {
      if (!quiet) {
        saveBtn.disabled = false;
        send.disabled = false;
        saveBtn.textContent = "Guardar";
        send.textContent = "Enviar registro";
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
  saveBtn.addEventListener("click", function () { persist("save"); });
  form.addEventListener("submit", function (event) {
    event.preventDefault();
    clearTimeout(timer);
    persist("send");
  });

  paintCount();
  const remembered = localStorage.getItem(memoryKey) || "";
  if (remembered) {
    rutInput.value = remembered;
    loadSession();
  }
})();
