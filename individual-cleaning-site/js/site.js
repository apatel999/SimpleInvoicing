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
    link.addEventListener("click", function () {
      closeMenu();
    });
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

  function tryImage(src) {
    return new Promise(function (resolve) {
      var img = new Image();
      img.onload = function () { resolve(src); };
      img.onerror = function () { resolve(""); };
      img.src = src;
    });
  }

  function firstExisting(paths) {
    return paths.reduce(function (chain, src) {
      return chain.then(function (found) {
        return found ? found : tryImage(src);
      });
    }, Promise.resolve(""));
  }

  var hero = document.querySelector(".hero-photo");
  if (hero) {
    firstExisting(["photos/hero.jpg", "photos/hero.jpeg", "photos/hero.png", "photos/hero.webp", "photos/photo-1.jpg"]).then(function (src) {
      if (src) {
        hero.style.backgroundImage = "linear-gradient(180deg, rgba(15,118,110,0.08), rgba(28,43,40,0.18)), url('" + src + "')";
        hero.style.backgroundSize = "cover";
        hero.style.backgroundPosition = "center";
      }
    });
  }

  var grid = document.querySelector("[data-photo-grid]");
  var workSection = document.querySelector("[data-work-section]");
  if (grid && workSection) {
    var pending = [];
    for (var i = 1; i <= 12; i++) {
      pending.push(firstExisting([
        "photos/photo-" + i + ".jpg",
        "photos/photo-" + i + ".jpeg",
        "photos/photo-" + i + ".png",
        "photos/photo-" + i + ".webp"
      ]));
    }
    Promise.all(pending).then(function (sources) {
      var found = 0;
      sources.forEach(function (src, index) {
        if (!src) {
          return;
        }
        found += 1;
        var fig = document.createElement("figure");
        fig.className = "gallery-item";
        fig.innerHTML = '<img src="' + src + '" alt="House cleaning photo ' + (index + 1) + '">';
        grid.appendChild(fig);
      });
      if (found) {
        workSection.hidden = false;
      }
    });
  }
})();
