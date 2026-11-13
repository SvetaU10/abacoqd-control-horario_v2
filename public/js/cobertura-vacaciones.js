(function () {
    const tarjeta = document.querySelector("[data-cobertura-pagina]");
    if (!tarjeta) {
        return;
    }

    const sede = tarjeta.querySelector("[data-cobertura-sede]");
    const formulario = tarjeta.querySelector("[data-cobertura-minimo]");
    const minimo = tarjeta.querySelector("[data-cobertura-minimo-valor]");

    function tiraDe(id) {
        return tarjeta.querySelector('[data-cobertura-tira="' + id + '"]');
    }

    function textoQuedan(quedan, plantilla, corta) {
        if (plantilla === 0) {
            return corta + " · sin plantilla";
        }
        return corta + " · " + (quedan === 1 ? "queda " : "quedan ") + quedan + " de " + plantilla;
    }

    function nivelDe(quedan, plantilla, minimoSede) {
        if (plantilla === 0) {
            return "vacia";
        }
        if (quedan < minimoSede) {
            return "corto";
        }
        if (quedan === minimoSede) {
            return "justo";
        }
        return "ok";
    }

    function pintar(tira, solicitudId) {
        const minimoSede = Number(tira.dataset.minimo);
        tira.querySelectorAll(".cobertura-dia").forEach(function (dia) {
            const plantilla = Number(dia.dataset.plantilla);
            const fuera = Number(dia.dataset.fuera);
            const pendientes = (dia.dataset.pendientes || "").split(",").filter(Boolean);
            const incluye = solicitudId && pendientes.indexOf(String(solicitudId)) !== -1;
            const quedan = Math.max(0, plantilla - fuera - (incluye ? 1 : 0));
            const nivel = incluye || solicitudId ? nivelDe(quedan, plantilla, minimoSede) : dia.dataset.nivel;
            const otros = pendientes.filter(function (id) {
                return id !== String(solicitudId || "");
            });
            const titulo = solicitudId ? textoQuedan(quedan, plantilla, dia.dataset.corta) : dia.dataset.titulo;
            dia.className = "cobertura-dia cobertura-" + (solicitudId ? nivel : dia.dataset.nivel);
            if ((solicitudId && otros.length) || (!solicitudId && pendientes.length)) {
                dia.classList.add("tiene-pendiente");
            }
            if (incluye) {
                dia.classList.add("es-solicitud");
            }
            dia.title = titulo;
            const texto = dia.querySelector("[data-cobertura-texto]");
            if (texto) {
                texto.textContent = titulo;
            }
        });
    }

    function mostrarSede(id) {
        tarjeta.querySelectorAll("[data-cobertura-tira]").forEach(function (tira) {
            const activa = tira.dataset.coberturaTira === String(id);
            tira.hidden = !activa;
            if (activa) {
                pintar(tira, null);
            }
        });
        if (formulario) {
            formulario.action = formulario.action.replace(/centros\/\d+\/minimo/, "centros/" + id + "/minimo");
        }
        if (minimo && sede) {
            const opcion = sede.selectedOptions[0];
            if (opcion) {
                minimo.value = opcion.dataset.minimo;
            }
        }
    }

    function cerrar() {
        tarjeta.querySelectorAll(".gestion-solicitud[data-abierta]").forEach(function (fila) {
            delete fila.dataset.abierta;
            const boton = fila.querySelector(".cobertura-abrir");
            const detalle = fila.querySelector(".cobertura-detalle");
            if (boton) {
                boton.setAttribute("aria-expanded", "false");
            }
            if (detalle) {
                detalle.hidden = true;
            }
        });
    }

    tarjeta.querySelectorAll(".cobertura-abrir").forEach(function (boton) {
        boton.addEventListener("click", function () {
            const fila = boton.closest(".gestion-solicitud");
            const abierta = fila.hasAttribute("data-abierta");
            const sedeId = fila.dataset.sede;
            cerrar();
            if (sede && sedeId) {
                sede.value = sedeId;
                mostrarSede(sedeId);
            }
            const tira = tiraDe(sedeId) || tarjeta.querySelector("[data-cobertura-tira]:not([hidden])");
            if (abierta || !tira) {
                if (tira) {
                    pintar(tira, null);
                }
                return;
            }
            fila.dataset.abierta = "1";
            boton.setAttribute("aria-expanded", "true");
            const detalle = fila.querySelector(".cobertura-detalle");
            if (detalle) {
                detalle.hidden = false;
            }
            pintar(tira, fila.dataset.solicitud);
            const marcado = tira.querySelector(".es-solicitud");
            if (marcado) {
                marcado.scrollIntoView({ inline: "center", block: "nearest" });
            }
        });
    });

    if (sede) {
        sede.addEventListener("change", function () {
            cerrar();
            mostrarSede(sede.value);
        });
    }

    if (minimo && formulario) {
        minimo.addEventListener("change", function () {
            if (minimo.reportValidity()) {
                formulario.requestSubmit();
            }
        });
    }
})();
