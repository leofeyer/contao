import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static values = {
        table: String,
    }

    static targets = ['navigation', 'jump'];
    static classes = ['collapsed'];

    static afterLoad (identifier, application) {
        const migrateLegacy = () => {
            document.querySelectorAll('legend[data-toggle-fieldset]').forEach(function(el) {
                console && console.warn(`Using "data-toggle-fieldset" attribute on fieldset legend is deprecated and will be removed in Contao 6. Apply the Stimulus actions instead.`);

                const { id } = JSON.parse(el.getAttribute('data-toggle-fieldset'));

                el.setAttribute(`data-${identifier}-target`, 'jump');
                el.setAttribute('data-action', `click->${identifier}#toggle`);
                el.setAttribute(`data-${identifier}-id-param`, id);
            });
        }

        // called as soon as registered so DOM may not have loaded yet
        if (document.readyState === "loading") {
            document.addEventListener("DOMContentLoaded", migrateLegacy)
        } else {
            migrateLegacy();
        }
    }

    initialize () {
        AjaxRequest.toggleFieldset = (el, id, table) => {
            window.console && console.warn('Using AjaxRequest.toggleFieldset is deprecated and will be removed in Contao 6. Apply the Stimulus actions instead.');

            if (table !== this.tableValue) {
                return;
            }

            const event = new CustomEvent('toggleFieldset');
            event.params = { id };
            this.toggle(event);
        };
    }

    connect () {
        this.rebuildNavigation();
        this.connected = true;
    }

    jumpTargetConnected (el) {
        const id = el.getAttribute(`data-${this.identifier}-id-param`);
        const fieldset = document.getElementById(`pal_${id}`);

        if (!fieldset) {
            return;
        }

        if (fieldset.querySelectorAll('label.error, label.mandatory').length) {
            fieldset.classList.remove(this.collapsedClass);
        } else if (fieldset.classList.contains('hide')) {
            console && console.warn(`Using "hide" class on fieldset is deprecated and will will be removed in Contao 6. Use "${this.collapsedClass}" instead.`);
            fieldset.classList.add(this.collapsedClass);
        }

        if (!this.connected) {
            return;
        }

        this.rebuildNavigation();
    }

    toggle (event) {
        const fieldset = document.getElementById(`pal_${event.params.id}`);

        if (!fieldset) {
            return;
        }

        if (fieldset.classList.contains(this.collapsedClass)) {
            this.open(event);
        } else {
            this.close(event);
        }
    }

    open (event) {
        const fieldset = document.getElementById(`pal_${event.params.id}`);

        if (!fieldset || !fieldset.classList.contains(this.collapsedClass)) {
            return;
        }

        fieldset.classList.remove(this.collapsedClass);
        this.storeState(event.params.id, 1);
    }

    close (event) {
        const fieldset = document.getElementById(`pal_${event.params.id}`);

        if (!fieldset || fieldset.classList.contains(this.collapsedClass)) {
            return;
        }

        const form = fieldset.closest('form');
        const input = fieldset.querySelectorAll('[required]');
        let collapse = true;

        for (let i = 0; i < input.length; i++) {
            if (!input[i].value) {
                collapse = false;
                break;
            }
        }

        if (!collapse) {
            if (typeof(form.checkValidity) == 'function') {
                form.querySelector('button[type="submit"]').click();
            }
        } else {
            fieldset.classList.add(this.collapsedClass);
            this.storeState(event.params.id, 0);
        }
    }

    rebuildNavigation () {
        if (!this.hasNavigationTarget) {
            return;
        }

        const links = [];

        this.jumpTargets.forEach((el) => {
            const id = el.getAttribute(`data-${this.identifier}-id-param`);

            const link = document.createElement('a');
            link.href = `#pal_${id}`;
            link.innerText = el.innerText;
            link.dataset.action = 'contao--fieldset#open';
            link.setAttribute(`data-${this.identifier}-id-param`, id);

            const li = document.createElement('li');
            li.append(link);
            links.push(li);
        });

        this.navigationTarget.innerHTML = '';
        this.navigationTarget.append(...links);
    }

    storeState (id, state) {
        fetch(window.location.href, {
            method: 'POST',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                action: 'toggleFieldset',
                id: id,
                table: this.tableValue,
                state: state,
            })
        });
    }
}
