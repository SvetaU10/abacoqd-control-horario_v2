(function () {
    const zona = "Europe/Madrid";
    const reloj = document.querySelector("[data-clock]");
    const llevas = document.querySelector("[data-llevas]");
    let ahora = reloj ? Number(reloj.dataset.serverMs) : Date.now();

    const formatoHora = new Intl.DateTimeFormat("en-GB", {
        timeZone: zona,
        hour: "2-digit",
        minute: "2-digit",
        second: "2-digit",
        hourCycle: "h23",
    });

    function textoHora(fecha) {
        const partes = {};
        formatoHora.formatToParts(fecha).forEach(function (parte) {
            partes[parte.type] = parte.value;
        });
        return partes.hour + ":" + partes.minute + ":" + partes.second;
    }

    function sincronizarHora(caja) {
        const horas = caja.querySelector("[data-parte=h]");
        const minutos = caja.querySelector("[data-parte=m]");
        const oculto = caja.querySelector("[data-hora-valor]");
        const minimo = caja.dataset.min || "";
        const maximo = caja.dataset.max || "";

        Array.from(horas.options).forEach(function (opcion) {
            if (!opcion.value) {
                return;
            }
            const pronto = minimo && opcion.value < minimo.slice(0, 2);
            const tarde = maximo && opcion.value > maximo.slice(0, 2);
            opcion.disabled = pronto || tarde;
        });

        if (horas.selectedOptions[0] && horas.selectedOptions[0].disabled) {
            horas.value = "";
        }

        Array.from(minutos.options).forEach(function (opcion) {
            if (!opcion.value) {
                return;
            }
            let fuera = false;
            if (horas.value && minimo && horas.value === minimo.slice(0, 2) && opcion.value < minimo.slice(3, 5)) {
                fuera = true;
            }
            if (horas.value && maximo && horas.value === maximo.slice(0, 2) && opcion.value > maximo.slice(3, 5)) {
                fuera = true;
            }
            opcion.disabled = fuera;
        });

        if (minutos.selectedOptions[0] && minutos.selectedOptions[0].disabled) {
            minutos.value = "";
        }

        oculto.value = horas.value && minutos.value ? horas.value + ":" + minutos.value : "";
    }

    document.querySelectorAll("[data-hora]").forEach(function (caja) {
        sincronizarHora(caja);
        caja.addEventListener("change", function () {
            sincronizarHora(caja);
        });
    });

    function textoMinutos(total) {
        const horas = Math.floor(total / 60);
        const minutos = total % 60;
        if (horas === 0) {
            return "Hoy llevas " + minutos + " min.";
        }
        return "Hoy llevas " + horas + " h " + minutos + " min.";
    }

    function tick() {
        ahora += 1000;
        if (reloj) {
            reloj.textContent = textoHora(new Date(ahora));
        }
        if (llevas && llevas.dataset.abiertoMs) {
            const cerrados = Number(llevas.dataset.cerrados || 0);
            const extra = Math.max(0, Math.floor((ahora - Number(llevas.dataset.abiertoMs)) / 60000));
            const total = cerrados + extra;
            if (llevas.dataset.corto === "1") {
                const minutos = total % 60;
                const corto = Math.floor(total / 60) + " h " + (minutos < 10 ? "0" : "") + minutos;
                llevas.textContent = llevas.dataset.min === "1" ? corto + " min" : corto;
            } else {
                llevas.textContent = textoMinutos(total);
            }
            const semana = document.querySelector("[data-semana]");
            if (semana) {
                const base = Number(semana.dataset.cerrados || 0) + extra;
                const minutosSemana = base % 60;
                semana.textContent = Math.floor(base / 60) + " h " + (minutosSemana < 10 ? "0" : "") + minutosSemana;
            }
            document.querySelectorAll("[data-eje-vivo]").forEach(function (barra) {
                const partes = {};
                formatoHora.formatToParts(new Date(ahora)).forEach(function (parte) {
                    partes[parte.type] = parte.value;
                });
                const minuto = Number(partes.hour) * 60 + Number(partes.minute);
                const fin = (Math.max(7 * 60, Math.min(21 * 60, minuto)) - 7 * 60) / (14 * 60) * 100;
                const left = Number(barra.dataset.ejeLeft);
                barra.style.width = Math.max(0, fin - left) + "%";
            });
        }
    }

    if (reloj || (llevas && llevas.dataset.abiertoMs)) {
        window.setInterval(tick, 1000);
    }

    const titulo = document.title;
    function tituloPendiente() {
        const escondida = document.hidden && document.body.dataset.pendiente === "1";
        document.title = escondida ? "Pendiente · Jornada" : titulo;
    }
    document.addEventListener("visibilitychange", tituloPendiente);

    const dialogoTemprano = document.getElementById("confirma-temprano");
    if (dialogoTemprano) {
        document.querySelectorAll("form[data-confirma-temprano]").forEach(function (form) {
            form.addEventListener("submit", function (event) {
                if (typeof dialogoTemprano.showModal !== "function") {
                    return;
                }
                event.preventDefault();
                dialogoTemprano.showModal();
            });
        });
        const cierraTemprano = dialogoTemprano.querySelector("[data-cierra-dialogo]");
        if (cierraTemprano) {
            cierraTemprano.addEventListener("click", function () {
                dialogoTemprano.close();
            });
        }
        if (dialogoTemprano.hasAttribute("data-abrir") && typeof dialogoTemprano.showModal === "function") {
            dialogoTemprano.showModal();
        }
    }

    document.querySelectorAll("form").forEach(function (form) {
        form.addEventListener("submit", function (event) {
            form.querySelectorAll("[data-hora]").forEach(sincronizarHora);
            const rango = form.querySelector("[data-rango]");
            if (rango) {
                const desdeRango = rango.querySelector("[name=desde]");
                const hastaRango = rango.querySelector("[name=hasta]");
                const avisoRango = rango.querySelector("[data-rango-aviso]");
                if (!desdeRango.value || !hastaRango.value) {
                    event.preventDefault();
                    if (avisoRango) {
                        avisoRango.hidden = false;
                    }
                    const abreRango = rango.querySelector("[data-rango-abre]");
                    const panelRango = rango.querySelector("[data-rango-panel]");
                    if (abreRango && panelRango) {
                        abreRango.setAttribute("aria-expanded", "true");
                        panelRango.hidden = false;
                    }
                    return;
                }
            }
            if (!navigator.onLine && form.querySelector("[data-hora]")) {
                event.preventDefault();
                const aviso = document.querySelector("[data-sin-conexion]");
                if (aviso) {
                    aviso.hidden = false;
                }
                return;
            }
            if ((form.getAttribute("method") || "get").toLowerCase() !== "post") {
                return;
            }
            if (form.hasAttribute("data-confirma-temprano") && dialogoTemprano && typeof dialogoTemprano.showModal === "function") {
                return;
            }
            if (event.defaultPrevented) {
                return;
            }
            if (form.dataset.enviando === "1") {
                event.preventDefault();
                return;
            }
            form.dataset.enviando = "1";
        });
    });

    try {
        localStorage.removeItem("jornada-pendientes");
    } catch (error) {
        // Si el navegador no deja usar el almacén, no hay nada que reenviar.
    }

    window.addEventListener("online", function () {
        const aviso = document.querySelector("[data-sin-conexion]");
        const vuelta = document.querySelector("[data-conexion-vuelta]");
        if (aviso && !aviso.hidden && vuelta) {
            vuelta.hidden = false;
        }
    });

    document.querySelectorAll("[data-rango]").forEach(function (caja) {
        const abre = caja.querySelector("[data-rango-abre]");
        const panel = caja.querySelector("[data-rango-panel]");
        const dias = caja.querySelector("[data-rango-dias]");
        const titulo = caja.querySelector("[data-rango-titulo]");
        const desde = caja.querySelector("[name=desde]");
        const hasta = caja.querySelector("[name=hasta]");
        const aviso = caja.querySelector("[data-rango-aviso]");
        const nombres = ["enero", "febrero", "marzo", "abril", "mayo", "junio", "julio", "agosto", "septiembre", "octubre", "noviembre", "diciembre"];
        let primero = desde.value || "";
        let ultimo = hasta.value || "";

        function hoy() {
            return new Intl.DateTimeFormat("en-CA", {
                timeZone: "Europe/Madrid",
                year: "numeric",
                month: "2-digit",
                day: "2-digit",
            }).format(new Date());
        }

        function iso(fecha) {
            const mes = String(fecha.getMonth() + 1).padStart(2, "0");
            const dia = String(fecha.getDate()).padStart(2, "0");
            return fecha.getFullYear() + "-" + mes + "-" + dia;
        }

        function texto(valor) {
            const partes = valor.split("-");
            return partes[2] + "/" + partes[1] + "/" + partes[0];
        }

        function mesDe(valor) {
            const partes = (valor || hoy()).split("-");
            return new Date(Number(partes[0]), Number(partes[1]) - 1, 1);
        }

        let cursor = mesDe(primero);

        function resumen() {
            if (primero && ultimo) {
                abre.textContent = texto(primero) + " – " + texto(ultimo);
            } else if (primero) {
                abre.textContent = texto(primero) + " – elige el último día";
            } else {
                abre.textContent = "Elige el primer y el último día";
            }
            desde.value = primero;
            hasta.value = primero && ultimo ? ultimo : "";
            if (aviso && primero && ultimo) {
                aviso.hidden = true;
            }
        }

        function pintar() {
            titulo.textContent = nombres[cursor.getMonth()] + " " + cursor.getFullYear();
            dias.replaceChildren();
            ["L", "M", "X", "J", "V", "S", "D"].forEach(function (letra) {
                const cabecera = document.createElement("span");
                cabecera.className = "rango-letra";
                cabecera.textContent = letra;
                dias.appendChild(cabecera);
            });
            const inicio = new Date(cursor.getFullYear(), cursor.getMonth(), 1);
            const huecos = (inicio.getDay() + 6) % 7;
            const total = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0).getDate();
            const limite = hoy();
            for (let i = 0; i < huecos; i++) {
                const vacio = document.createElement("span");
                dias.appendChild(vacio);
            }
            for (let numero = 1; numero <= total; numero++) {
                const fecha = new Date(cursor.getFullYear(), cursor.getMonth(), numero);
                const valor = iso(fecha);
                const boton = document.createElement("button");
                boton.type = "button";
                boton.textContent = String(numero);
                boton.disabled = valor < limite;
                if (valor === primero || valor === ultimo) {
                    boton.className = "rango-elegido";
                } else if (primero && ultimo && valor > primero && valor < ultimo) {
                    boton.className = "rango-medio";
                }
                boton.addEventListener("click", function () {
                    if (!primero || ultimo) {
                        primero = valor;
                        ultimo = "";
                    } else if (valor < primero) {
                        ultimo = primero;
                        primero = valor;
                    } else {
                        ultimo = valor;
                    }
                    resumen();
                    pintar();
                    if (primero && ultimo) {
                        panel.hidden = true;
                        abre.setAttribute("aria-expanded", "false");
                    }
                });
                dias.appendChild(boton);
            }
        }

        abre.addEventListener("click", function () {
            const abierto = abre.getAttribute("aria-expanded") === "true";
            panel.hidden = abierto;
            abre.setAttribute("aria-expanded", abierto ? "false" : "true");
            if (!abierto) {
                pintar();
            }
        });

        caja.querySelector("[data-rango-prev]").addEventListener("click", function () {
            cursor = new Date(cursor.getFullYear(), cursor.getMonth() - 1, 1);
            pintar();
        });
        caja.querySelector("[data-rango-next]").addEventListener("click", function () {
            cursor = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 1);
            pintar();
        });

        resumen();
    });
})();

(function () {
    const caja = document.querySelector("[data-calendario]");
    if (!caja) {
        return;
    }

    // El botón «Pedir vacaciones» vive dentro del fragmento que se recarga.
    const barra = document.querySelector("[data-vacaciones-barra]");
    const texto = document.querySelector("[data-vacaciones-texto]");
    const formulario = document.querySelector("[data-vacaciones-form]");
    let pedido = 0;
    let eligiendo = false;
    let desde = "";
    let hasta = "";

    function pintarRango() {
        caja.querySelectorAll("[data-fecha]").forEach(function (dia) {
            const fecha = dia.dataset.fecha;
            const dentro = desde && (hasta
                ? fecha >= desde && fecha <= hasta
                : fecha === desde);
            dia.classList.toggle("casilla-rango", Boolean(dentro));
        });
    }

    function cargar(url, guardar) {
        const id = ++pedido;
        const destino = new URL(url, window.location.origin);
        destino.searchParams.set("fragmento", "1");

        return fetch(destino.toString(), { headers: { Accept: "text/html" } })
            .then(function (respuesta) {
                if (!respuesta.ok) {
                    throw new Error("calendario");
                }
                return respuesta.text();
            })
            .then(function (html) {
                if (id !== pedido) {
                    return;
                }
                caja.innerHTML = html;
                pintarRango();
                sincronizarPedir();
                if (guardar) {
                    const visible = new URL(url, window.location.origin);
                    visible.searchParams.delete("fragmento");
                    history.pushState({}, "", visible.pathname + visible.search);
                }
            });
    }

    // El servidor pinta el botón oculto: sin JavaScript no haría nada.
    function sincronizarPedir() {
        const sinBarra = !barra || !texto || !formulario;
        caja.querySelectorAll("[data-vacaciones-pedir]").forEach(function (boton) {
            boton.hidden = eligiendo || sinBarra;
        });
    }

    function abrirEleccion() {
        if (!barra || !texto || !formulario) {
            return;
        }
        eligiendo = true;
        desde = "";
        hasta = "";
        caja.dataset.vacacionesEligiendo = "1";
        sincronizarPedir();
        barra.hidden = false;
        texto.textContent = "Elige un rango de fechas";
        texto.focus({ preventScroll: true });
        pintarRango();
    }

    function cerrarEleccion() {
        eligiendo = false;
        desde = "";
        hasta = "";
        delete caja.dataset.vacacionesEligiendo;
        barra.hidden = true;
        sincronizarPedir();
        cargar(window.location.href, false).then(function () {
            const boton = caja.querySelector("[data-vacaciones-pedir]");
            if (boton) {
                boton.focus({ preventScroll: true });
            }
        }).catch(function () {
            window.location.reload();
        });
    }

    function marcar(fecha) {
        if (!desde || hasta) {
            desde = fecha;
            hasta = "";
        } else if (fecha < desde) {
            hasta = desde;
            desde = fecha;
        } else {
            hasta = fecha;
        }
        if (texto) {
            texto.textContent = hasta
                ? "Rango seleccionado. Puedes aceptar la solicitud."
                : "Elige el último día";
        }
        pintarRango();
    }

    sincronizarPedir();

    const cancelar = document.querySelector("[data-vacaciones-cancelar]");
    if (cancelar) {
        cancelar.addEventListener("click", cerrarEleccion);
    }
    const aceptar = document.querySelector("[data-vacaciones-aceptar]");
    if (aceptar && formulario) {
        aceptar.addEventListener("click", function () {
            if (!desde || !hasta) {
                if (texto) {
                    texto.textContent = "Elige un rango de fechas";
                }
                return;
            }
            formulario.querySelector("[data-vacaciones-desde]").value = desde;
            formulario.querySelector("[data-vacaciones-hasta]").value = hasta;
            formulario.submit();
        });
    }

    caja.addEventListener("click", function (evento) {
        if (!eligiendo && evento.target.closest("[data-vacaciones-pedir]")) {
            abrirEleccion();
            return;
        }
        if (eligiendo) {
            const dia = evento.target.closest("[data-fecha]");
            if (dia) {
                evento.preventDefault();
                marcar(dia.dataset.fecha);
                return;
            }
        }

        const enlace = evento.target.closest("[data-calendario-ir]");
        if (!enlace || evento.metaKey || evento.ctrlKey || evento.shiftKey || evento.altKey) {
            return;
        }
        evento.preventDefault();
        cargar(enlace.href, true).catch(function () {
            window.location.href = enlace.href;
        });
    });

    window.addEventListener("popstate", function () {
        cargar(window.location.href, false).catch(function () {
            window.location.reload();
        });
    });
})();

(function () {
    const dialogo = document.querySelector("[data-historial]");
    const abre = document.querySelector("[data-historial-abre]");
    if (!dialogo) {
        return;
    }
    const cerrar = dialogo.querySelector("[data-historial-cerrar]");
    if (abre) {
        abre.addEventListener("click", function () {
            dialogo.showModal();
        });
    }
    if (cerrar) {
        cerrar.addEventListener("click", function () {
            dialogo.close();
        });
    }
    dialogo.addEventListener("click", function (evento) {
        if (evento.target === dialogo) {
            dialogo.close();
        }
    });
    if (dialogo.hasAttribute("data-abierto")) {
        dialogo.showModal();
    }
})();

(function () {
    const dialogo = document.querySelector("[data-consulta-dialogo]");
    const formulario = document.querySelector("[data-consulta-form]");
    const destino = document.querySelector("[data-consulta-destino-input]");

    if (!dialogo || !formulario || !destino) {
        return;
    }

    document.querySelectorAll("[data-consulta-abre]").forEach(function (enlace) {
        enlace.addEventListener("click", function (evento) {
            evento.preventDefault();
            formulario.action = enlace.getAttribute("data-consulta-action");
            destino.value = enlace.getAttribute("data-consulta-destino");
            formulario.querySelector("textarea").value = "";
            dialogo.showModal();
            formulario.querySelector("textarea").focus();
        });
    });

    document.querySelector("[data-consulta-cancelar]").addEventListener("click", function () {
        dialogo.close();
    });

    dialogo.addEventListener("click", function (evento) {
        if (evento.target === dialogo) {
            dialogo.close();
        }
    });
})();

(function () {
    document.querySelectorAll("[data-dialogo-abre]").forEach(function (boton) {
        boton.addEventListener("click", function () {
            const dialogo = document.querySelector('[data-dialogo="' + boton.getAttribute("data-dialogo-abre") + '"]');
            if (dialogo && typeof dialogo.showModal === "function") {
                dialogo.showModal();
            }
        });
    });

    document.querySelectorAll("dialog[data-dialogo]").forEach(function (dialogo) {
        var cerrar = dialogo.querySelector("[data-dialogo-cerrar]");
        if (cerrar) {
            cerrar.addEventListener("click", function () {
                dialogo.close();
            });
        }
        dialogo.addEventListener("click", function (evento) {
            if (evento.target === dialogo) {
                dialogo.close();
            }
        });
        if (dialogo.hasAttribute("data-abierto")) {
            dialogo.showModal();
        }
    });
})();

(function () {
    document.querySelectorAll("[data-motivo-correccion]").forEach(function (motivo) {
        const formulario = motivo.form;
        const inicio = formulario ? formulario.querySelector('[data-correccion-hora="inicio"]') : null;
        const fin = formulario ? formulario.querySelector('[data-correccion-hora="fin"]') : null;
        if (!inicio || !fin) {
            return;
        }

        function limpiarHora(caja) {
            caja.querySelectorAll("select").forEach(function (campo) {
                campo.value = "";
            });
            const oculto = caja.querySelector("[data-hora-valor]");
            if (oculto) {
                oculto.value = "";
            }
        }

        function actualizar() {
            const esPausa = motivo.value === "fichar_pausa";
            [inicio, fin].forEach(function (caja) {
                caja.querySelectorAll("[data-etiqueta-normal]").forEach(function (etiqueta) {
                    etiqueta.hidden = esPausa;
                });
                caja.querySelectorAll("[data-etiqueta-pausa]").forEach(function (etiqueta) {
                    etiqueta.hidden = !esPausa;
                });
            });
            if (esPausa) {
                limpiarHora(inicio);
                limpiarHora(fin);
            }
        }

        motivo.addEventListener("change", actualizar);
        actualizar();
    });
})();

(function () {
    document.querySelectorAll("textarea[data-max]").forEach(function (campo) {
        var maximo = Number(campo.getAttribute("data-max")) || 500;
        if (!campo.form) {
            return;
        }

        campo.addEventListener("input", function () {
            campo.setCustomValidity("");
        });

        campo.form.addEventListener("submit", function (evento) {
            var minimo = Number(campo.getAttribute("data-min")) || 1;
            var texto = campo.value.trim();
            if (texto.length < 1) {
                campo.setCustomValidity(campo.getAttribute("data-vacio") || "Escribe un texto.");
            } else if (texto.length < minimo) {
                campo.setCustomValidity(campo.getAttribute("data-corto") || "El texto tiene que tener al menos " + minimo + " caracteres.");
            } else if (texto.length > maximo) {
                campo.setCustomValidity(campo.getAttribute("data-largo") || "El texto puede tener hasta " + maximo + " caracteres.");
            } else {
                campo.setCustomValidity("");
                return;
            }
            evento.preventDefault();
            campo.reportValidity();
        });
    });
})();

(function () {
    document.querySelectorAll("[data-inicio-tarde]").forEach(function (formulario) {
        var otra = formulario.querySelector("[data-inicio-otra]");
        var cajaHora = formulario.querySelector("[data-inicio-hora]");
        var hora = formulario.querySelector("[name=hora]");
        var partesHora = cajaHora.querySelectorAll("[data-parte]");
        if (!otra || !cajaHora || !hora) {
            return;
        }

        function actualizar() {
            var mostrar = otra.checked;
            cajaHora.hidden = !mostrar;
            partesHora.forEach(function (parte) {
                parte.required = mostrar;
            });
            if (!mostrar) {
                hora.value = "";
            }
        }

        formulario.querySelectorAll("[name=modo]").forEach(function (opcion) {
            opcion.addEventListener("change", actualizar);
        });
        actualizar();
    });
})();

(function () {
    function aIso(valor) {
        var partes = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(valor);
        if (!partes) {
            return "";
        }
        var dia = Number(partes[1]);
        var mes = Number(partes[2]);
        var anio = Number(partes[3]);
        var fecha = new Date(anio, mes - 1, dia);
        if (fecha.getFullYear() !== anio || fecha.getMonth() !== mes - 1 || fecha.getDate() !== dia) {
            return "";
        }
        return partes[3] + "-" + partes[2] + "-" + partes[1];
    }

    function aTexto(valor) {
        var partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(valor || "");
        return partes ? partes[3] + "/" + partes[2] + "/" + partes[1] : "";
    }

    document.querySelectorAll("[data-campo-fecha]").forEach(function (caja) {
        var texto = caja.querySelector("[data-fecha-texto]");
        var iso = caja.querySelector("[data-fecha-iso]");
        var nativo = caja.querySelector("[data-fecha-nativo]");
        var abre = caja.querySelector("[data-fecha-abre]");
        if (!texto || !iso || !nativo) {
            return;
        }

        function dentroDeLimites(valor) {
            if (texto.dataset.min && valor < texto.dataset.min) {
                return false;
            }
            if (texto.dataset.max && valor > texto.dataset.max) {
                return false;
            }
            return true;
        }

        function sincronizarDesdeTexto() {
            var escrito = texto.value.trim();
            var convertido = aIso(escrito);
            if (convertido && !dentroDeLimites(convertido)) {
                convertido = "";
            }
            iso.value = convertido;
            if (convertido) {
                nativo.value = convertido;
            }
            if (escrito === "") {
                texto.setCustomValidity("");
            } else if (!convertido) {
                texto.setCustomValidity("Usa el día, el mes y el año: dd/mm/aaaa.");
            } else {
                texto.setCustomValidity("");
            }
        }

        texto.addEventListener("input", function () {
            var digitos = texto.value.replace(/\D/g, "").slice(0, 8);
            var armado = digitos;
            if (digitos.length > 4) {
                armado = digitos.slice(0, 2) + "/" + digitos.slice(2, 4) + "/" + digitos.slice(4);
            } else if (digitos.length > 2) {
                armado = digitos.slice(0, 2) + "/" + digitos.slice(2);
            }
            if (armado !== texto.value) {
                texto.value = armado;
            }
            sincronizarDesdeTexto();
        });
        texto.addEventListener("change", sincronizarDesdeTexto, true);

        nativo.addEventListener("change", function () {
            texto.value = aTexto(nativo.value);
            iso.value = nativo.value;
            texto.setCustomValidity("");
            texto.dispatchEvent(new Event("change", { bubbles: true }));
        });

        if (abre) {
            abre.addEventListener("click", function () {
                if (typeof nativo.showPicker === "function") {
                    nativo.showPicker();
                    return;
                }
                nativo.focus();
            });
        }
    });
})();
