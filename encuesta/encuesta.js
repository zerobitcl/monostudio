(function () {
  const form = document.getElementById("survey");
  const texto = document.getElementById("texto");
  const count = document.getElementById("count");
  const note = document.getElementById("note");
  const send = document.getElementById("send");
  const done = document.getElementById("done");

  function paintCount() {
    count.textContent = texto.value.length + " / 8000";
    texto.style.height = "auto";
    texto.style.height = Math.max(texto.scrollHeight, 220) + "px";
  }

  texto.addEventListener("input", paintCount);
  paintCount();

  document.getElementById("again").addEventListener("click", function () {
    form.reset();
    paintCount();
    done.hidden = true;
    form.hidden = false;
    note.hidden = true;
    texto.focus();
  });

  form.addEventListener("submit", async function (event) {
    event.preventDefault();
    note.hidden = true;
    const nombre = document.getElementById("nombre").value.trim();
    const body = texto.value.trim();
    if (nombre.length < 2 || body.length < 10) {
      note.hidden = false;
      note.textContent = "Escribe tu nombre y al menos un párrafo breve.";
      return;
    }
    send.disabled = true;
    send.textContent = "Enviando…";
    try {
      const res = await fetch("submit.php", {
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({
          nombre: nombre,
          texto: body,
          empresa: form.empresa.value,
        }),
      });
      const data = await res.json().catch(function () { return {}; });
      if (!res.ok || data.error) throw new Error(data.error || "No se pudo guardar");
      form.hidden = true;
      done.hidden = false;
    } catch (err) {
      note.hidden = false;
      note.textContent = err.message || "No se pudo guardar. Intenta de nuevo.";
    } finally {
      send.disabled = false;
      send.textContent = "Enviar registro";
    }
  });
})();
