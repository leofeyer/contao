import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['navigation', 'section'];

    connect () {
        this.rebuildNavigation();
        this.connected = true;
    }

    sectionTargetConnected () {
        if (!this.connected) {
            return;
        }

        this.rebuildNavigation();
    }

    rebuildNavigation () {
        if (!this.hasNavigationTarget) {
            return;
        }

        const links = [];

        this.sectionTargets.forEach((el) => {
            const action = document.createElement('button');
            action.innerText = el.innerText;
            action.addEventListener('click', (event) => {
                event.preventDefault();
                this.dispatch('scrollto', { target: el });
                el.parentNode.scrollIntoView();
            })

            const li = document.createElement('li');
            li.append(action);
            links.push(li);
        });

        this.navigationTarget.innerHTML = '';
        this.navigationTarget.append(...links);
    }
}
