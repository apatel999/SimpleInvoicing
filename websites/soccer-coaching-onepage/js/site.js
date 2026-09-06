(function () {
  var toggle = document.querySelector(".menu-toggle");
  var nav = document.querySelector(".nav");
  var links = document.querySelectorAll('.nav-links a[href^="#"]');
  var sections = [];

  links.forEach(function (link) {
    var id = link.getAttribute("href").slice(1);
    var section = document.getElementById(id);
    if (section) {
      sections.push({ id: id, el: section, link: link });
    }
  });

  function closeMenu() {
    if (!nav) {
      return;
    }
    nav.classList.remove("is-open");
    if (toggle) {
      toggle.setAttribute("aria-expanded", "false");
    }
  }

  if (toggle && nav) {
    toggle.addEventListener("click", function () {
      var open = nav.classList.toggle("is-open");
      toggle.setAttribute("aria-expanded", open ? "true" : "false");
    });
  }

  links.forEach(function (link) {
    link.addEventListener("click", closeMenu);
  });

  function setActive() {
    var fromTop = window.scrollY + 120;
    var current = sections[0];
    sections.forEach(function (item) {
      if (item.el.offsetTop <= fromTop) {
        current = item;
      }
    });
    links.forEach(function (link) {
      link.classList.toggle("is-active", current && link === current.link);
    });
  }

  window.addEventListener("scroll", setActive, { passive: true });
  setActive();

  var form = document.querySelector("[data-quote-form]");
  var notice = document.querySelector("[data-form-notice]");
  if (form && notice) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      notice.classList.add("is-visible");
      form.reset();
      notice.scrollIntoView({ behavior: "smooth", block: "center" });
    });
  }
})();
