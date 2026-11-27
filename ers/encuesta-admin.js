(function () {
  const meta = document.getElementById("meta");
  const link = document.getElementById("link");
  const notice = document.getElementById("notice");
  const entries = document.getElementById("entries");
  const report = document.getElementById("report");
  const analyze = document.getElementById("analyze");
  const printBtn = document.getElementById("print");
  let state = null;

  function showNotice(text) {
    notice.hidden = !text;
    notice.textContent = text || "";
  }

  function stamp(unix) {
    if (!unix) return "";
    return new Date(unix * 1000).toLocaleString("es-CL", { dateStyle: "short", timeStyle: "short" });
  }

  function paint(data) {
    state = data;
    const n = Number(data.count || 0);
    const sent = Number(data.sent || 0);
    const drafts = Number(data.drafts || 0);
    meta.textContent = (n === 1 ? "1 persona" : n + " personas")
      + " · " + sent + " enviados · " + drafts + " borradores";
    if (data.reportStale) meta.textContent += " · el informe no incluye los últimos registros";
    link.textContent = data.link || "https://monostudio.cl/encuesta/";
    entries.replaceChildren();
    (data.entries || []).forEach(function (row) {
      const card = document.createElement("article");
      card.className = "entry";
      const head = document.createElement("header");
      const who = document.createElement("strong");
      const bits = [row.nombre || "Sin nombre"];
      if (row.rut) bits.push(row.rut);
      bits.push(row.status === "borrador" ? "Borrador" : "Enviado");
      who.textContent = bits.join(" · ");
      const when = document.createElement("span");
      when.textContent = stamp(row.updatedAt || row.createdAt);
      const del = document.createElement("button");
      del.type = "button";
      del.className = "btn btn--ghost no-print";
      del.textContent = "Eliminar";
      del.addEventListener("click", function () { remove(row.id); });
      head.append(who, when, del);
      const body = document.createElement("p");
      body.textContent = row.texto || "";
      card.append(head, body);
      entries.appendChild(card);
    });
    paintReport(data.report, data.reportAt);
  }

  function paintReport(item, at) {
    report.replaceChildren();
    printBtn.hidden = !item;
    if (!item) {
      report.hidden = true;
      return;
    }
    report.hidden = false;
    const title = document.createElement("h2");
    title.textContent = "Informe del equipo";
    const when = document.createElement("p");
    when.className = "empty";
    when.textContent = [stamp(at), item.model || "", item.basedOn ? item.basedOn + " registros" : ""].filter(Boolean).join(" · ");
    const summary = document.createElement("p");
    summary.textContent = item.resumen_ejecutivo || "";
    report.append(title, when, summary);
    (item.problematicas || []).forEach(function (row) {
      const block = document.createElement("article");
      block.className = "problem is-" + (row.nivel || "media");
      const tag = document.createElement("span");
      tag.className = "tag";
      tag.textContent = (row.nivel || "") + " · " + (row.menciones || 1) + (row.menciones === 1 ? " mención" : " menciones");
      const heading = document.createElement("h3");
      heading.textContent = row.titulo || "";
      const text = document.createElement("p");
      text.textContent = row.descripcion || "";
      block.append(tag, heading, text);
      if (row.nombres && row.nombres.length) {
        const names = document.createElement("p");
        names.textContent = "Lo mencionan: " + row.nombres.join(", ");
        block.appendChild(names);
      }
      if (row.sugerencias && row.sugerencias.length) {
        const list = document.createElement("ul");
        row.sugerencias.forEach(function (tip) {
          const li = document.createElement("li");
          li.textContent = tip;
          list.appendChild(li);
        });
        block.appendChild(list);
      }
      report.appendChild(block);
    });
    if (item.mejoras_rapidas && item.mejoras_rapidas.length) {
      const heading = document.createElement("h3");
      heading.textContent = "Mejoras rápidas";
      const list = document.createElement("ul");
      item.mejoras_rapidas.forEach(function (tip) {
        const li = document.createElement("li");
        li.textContent = tip;
        list.appendChild(li);
      });
      report.append(heading, list);
    }
  }

  async function call(options) {
    const res = await fetch("api/encuesta.php", options);
    const data = await res.json().catch(function () { return {}; });
    if (!res.ok || data.error) throw new Error(data.error || "Error " + res.status);
    return data;
  }

  async function load() {
    try {
      paint(await call({ headers: { Accept: "application/json" } }));
    } catch (err) {
      meta.textContent = err.message || "No se pudo leer la encuesta.";
    }
  }

  async function remove(id) {
    if (!window.confirm("¿Eliminar este registro?")) return;
    try {
      paint(await call({
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ action: "delete", id: id }),
      }));
      showNotice("");
    } catch (err) {
      showNotice(err.message || "No se pudo eliminar.");
    }
  }

  document.getElementById("copy").addEventListener("click", async function () {
    const url = (state && state.link) || "https://monostudio.cl/encuesta/";
    try {
      await navigator.clipboard.writeText(url);
      showNotice("Enlace copiado.");
    } catch (err) {
      showNotice(url);
    }
  });

  analyze.addEventListener("click", async function () {
    analyze.disabled = true;
    analyze.textContent = "Cruzando registros…";
    showNotice("");
    try {
      const data = await call({
        method: "POST",
        headers: { "Content-Type": "application/json", Accept: "application/json" },
        body: JSON.stringify({ action: "analyze", force: false }),
      });
      paint(data);
      showNotice(data.cached ? "El informe ya estaba al día." : "Informe listo.");
    } catch (err) {
      showNotice(err.message || "No se pudo generar el informe.");
    } finally {
      analyze.disabled = false;
      analyze.textContent = "Generar informe con Gemini";
    }
  });

  printBtn.addEventListener("click", function () { window.print(); });
  load();
})();
