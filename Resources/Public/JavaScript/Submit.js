/**
 * Keeps a form from being sent twice while the server is still busy with it.
 *
 * Connecting and pushing take seconds, and a button that does nothing
 * visible invites a second click. Once the form is on its way, its buttons
 * are disabled and the one that was clicked shows a spinner.
 *
 * A plain script rather than a module: the agent runs on TYPO3 v11 too,
 * where the backend loads no ES modules of its own.
 */
(function () {
  var spinner = document.getElementById('caretaker2-spinner');

  var showBusy = function (form, submitter) {
    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
      button.disabled = true;
    });

    if (submitter && spinner && !submitter.querySelector('.icon-spinner-circle')) {
      submitter.insertAdjacentText('afterbegin', ' ');
      submitter.insertAdjacentElement('afterbegin', spinner.content.firstElementChild.cloneNode(true));
    }
  };

  var restore = function (form) {
    delete form.dataset.caretaker2Sent;
    form.querySelectorAll('button[type="submit"]').forEach(function (button) {
      button.disabled = false;
      var icon = button.querySelector('.icon-spinner-circle');
      if (icon) {
        icon.remove();
      }
    });
  };

  var forms = document.querySelectorAll('form[data-caretaker2-busy]');

  forms.forEach(function (form) {
    form.addEventListener('submit', function (event) {
      if (form.dataset.caretaker2Sent === '1') {
        event.preventDefault();
        return;
      }
      form.dataset.caretaker2Sent = '1';

      var submitter = event.submitter || form.querySelector('button[type="submit"]');

      // After the browser has collected the form data: a button disabled
      // while the submit event runs does not send its name and value, and
      // the server would not know which action was meant.
      window.setTimeout(function () {
        showBusy(form, submitter);
      }, 0);
    });
  });

  // Coming back through the browser's history restores the page as it was
  // left, spinner and all.
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      forms.forEach(restore);
    }
  });
})();
