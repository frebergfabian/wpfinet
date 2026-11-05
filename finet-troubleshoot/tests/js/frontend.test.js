/** @jest-environment jsdom */
import '../../assets/build/frontend.js';

describe('Finet Troubleshoot frontend behaviour', () => {
	beforeEach(() => {
		document.body.innerHTML = '<div id="guide"><div class="finet-troubleshoot__mount"></div></div>';
		window.confirm = jest.fn(() => true);
	});
	const data = () => ({ start_node: 'start', title: 'Test', settings: { general: { show_back: true, show_restart: true, progress_mode: 'step' } }, nodes: [
		{ id: 'start', type: 'question', title: 'Start', heading: 'Start', options: [{ id: 'yes', label: 'Ja', next: 'done' }] },
		{ id: 'done', type: 'result', result_type: 'success', title: 'Klart', heading: 'Klart', options: [] }
	] });
	test('branches forward, goes back and restarts independently', () => {
		const guide = window.FinetTroubleshootRuntime.mount(document.querySelector('#guide'), data());
		guide.go('done'); expect(guide.getPath()).toEqual(['start', 'done']);
		guide.back(); expect(guide.getPath()).toEqual(['start']);
		guide.go('done'); guide.restart(); expect(guide.getPath()).toEqual(['start']);
	});
});
