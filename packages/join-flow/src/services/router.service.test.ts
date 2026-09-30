import { redirectToPage, SAVED_STATE_KEY } from './router.service';

describe('redirectToPage', () => {
  // jsdom only implements same-document navigation, so target a fragment of
  // the current page to observe the browser actually moving.
  const target = () => `${window.location.origin}${window.location.pathname}#students`;

  beforeEach(() => {
    window.location.hash = '';
    sessionStorage.setItem(SAVED_STATE_KEY, JSON.stringify({ membership: 'student' }));
  });

  afterEach(() => {
    sessionStorage.clear();
  });

  it('sends the browser to the given page', () => {
    redirectToPage(target());
    expect(window.location.hash).toBe('#students');
  });

  it('clears the saved form state so returning starts afresh', () => {
    redirectToPage(target());
    expect(sessionStorage.getItem(SAVED_STATE_KEY)).toBeNull();
  });
});
