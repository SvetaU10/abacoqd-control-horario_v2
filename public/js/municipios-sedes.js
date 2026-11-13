(() => {
    document.querySelectorAll('[data-sede-municipio]').forEach((raiz) => {
        const campo = raiz.querySelector('input[type=search]');
        const ine = raiz.querySelector('[data-sede-ine]');
        const nombre = raiz.querySelector('[data-sede-nombre]');
        const comunidad = raiz.querySelector('[data-sede-comunidad]');
        const ayuda = raiz.querySelector('[data-sede-ayuda]');
        const opciones = raiz.querySelector('[role=listbox]');
        let temporizador, controlador, numero = 0, seleccion = -1, resultados = [];

        const cerrar = (cancelar = false) => {
            if (cancelar) {
                clearTimeout(temporizador);
                controlador?.abort();
                numero++;
            }
            opciones.hidden = true;
            campo.setAttribute('aria-expanded', 'false');
            campo.removeAttribute('aria-activedescendant');
            seleccion = -1;
        };
        const elegir = (fila) => {
            campo.value = fila.nombre + ' · ' + fila.provincia;
            ine.value = fila.ine;
            nombre.value = fila.nombre;
            comunidad.value = fila.comunidad;
            campo.setCustomValidity('');
            ayuda.textContent = fila.provincia + ' · ' + fila.comunidad + ' · España · INE ' + fila.ine;
            cerrar(true);
            campo.focus();
        };
        campo.addEventListener('input', () => {
            clearTimeout(temporizador);
            controlador?.abort();
            const consulta = ++numero;
            ine.value = nombre.value = comunidad.value = '';
            campo.setCustomValidity('');
            cerrar();
            if (campo.value.trim().length < 2) {
                ayuda.textContent = 'Escribe al menos dos letras o el código INE.';
                return;
            }
            ayuda.textContent = 'Buscando en el catálogo local…';
            temporizador = setTimeout(async () => {
                controlador = new AbortController();
                try {
                    const url = new URL(raiz.dataset.url, location.href);
                    url.searchParams.set('buscar', campo.value.trim());
                    const respuesta = await fetch(url, {headers: {'Accept': 'application/json'}, signal: controlador.signal});
                    if (!respuesta.ok) throw new Error();
                    const datos = await respuesta.json();
                    if (consulta !== numero) return;
                    resultados = datos.municipios;
                    opciones.replaceChildren();
                    resultados.forEach((fila, indice) => {
                        const opcion = document.createElement('button');
                        opcion.type = 'button';
                        opcion.tabIndex = -1;
                        opcion.id = opciones.id + '-' + indice;
                        opcion.setAttribute('role', 'option');
                        opcion.setAttribute('aria-selected', 'false');
                        opcion.textContent = fila.nombre + ' · ' + fila.provincia + ' · ' + fila.ine;
                        opcion.addEventListener('click', () => elegir(fila));
                        opciones.append(opcion);
                    });
                    opciones.hidden = resultados.length === 0;
                    campo.setAttribute('aria-expanded', String(resultados.length > 0));
                    ayuda.textContent = resultados.length === 0 ? 'No se han encontrado municipios.' : (datos.hay_mas ? 'Se muestran 25 resultados. Concreta el nombre o añade la provincia.' : 'Selecciona el municipio de la lista.');
                } catch (error) {
                    if (error.name !== 'AbortError' && consulta === numero) ayuda.textContent = 'No se puede consultar el catálogo local ahora. Inténtalo de nuevo.';
                }
            }, 250);
        });
        campo.addEventListener('keydown', (evento) => {
            if (evento.key === 'Escape') { cerrar(true); return; }
            if (evento.key === 'Tab') { cerrar(true); return; }
            if (opciones.hidden) return;
            if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
                evento.preventDefault();
                seleccion = seleccion < 0
                    ? (evento.key === 'ArrowDown' ? 0 : resultados.length - 1)
                    : (seleccion + (evento.key === 'ArrowDown' ? 1 : -1) + resultados.length) % resultados.length;
                Array.from(opciones.children).forEach((opcion, indice) => opcion.setAttribute('aria-selected', String(indice === seleccion)));
                campo.setAttribute('aria-activedescendant', opciones.children[seleccion].id);
                opciones.children[seleccion].scrollIntoView({block: 'nearest'});
            } else if (evento.key === 'Enter' && seleccion >= 0) {
                evento.preventDefault();
                elegir(resultados[seleccion]);
            }
        });
        document.addEventListener('click', (evento) => { if (!raiz.contains(evento.target)) cerrar(true); });
        raiz.closest('form').addEventListener('submit', (evento) => {
            if (!ine.value && !nombre.value) {
                evento.preventDefault();
                campo.setCustomValidity('Selecciona un municipio de la lista.');
                campo.reportValidity();
            }
        });
    });
})();
