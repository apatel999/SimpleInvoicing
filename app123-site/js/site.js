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

  var vantaRoot = document.getElementById("vanta-net");
  if (vantaRoot && window.VANTA && window.VANTA.NET) {
    var reduceMotion = window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    if (!reduceMotion) {
      window.VANTA.NET({
        el: vantaRoot,
        mouseControls: true,
        touchControls: true,
        gyroControls: false,
        minHeight: 200,
        minWidth: 200,
        scale: 1,
        scaleMobile: 1,
        color: 0x7eb3ef,
        backgroundColor: 0x163a6b,
        points: 12,
        maxDistance: 22,
        spacing: 16
      });
      var hint = document.querySelector(".effect-hint");
      var card = vantaRoot.closest(".effect-card");
      if (hint && card) {
        card.appendChild(hint);
      }
    }
  }

  var slides = document.querySelectorAll("[data-hero-slides] .hero-slide");
  var dots = document.querySelectorAll("[data-hero-dots] button");
  var slideIndex = 0;
  var slideTimer;

  function showSlide(index) {
    if (!slides.length) {
      return;
    }
    slideIndex = (index + slides.length) % slides.length;
    slides.forEach(function (slide, i) {
      slide.classList.toggle("is-active", i === slideIndex);
    });
    dots.forEach(function (dot, i) {
      dot.classList.toggle("is-active", i === slideIndex);
    });
  }

  function startSlides() {
    if (slideTimer) {
      window.clearInterval(slideTimer);
    }
    slideTimer = window.setInterval(function () {
      showSlide(slideIndex + 1);
    }, 4500);
  }

  dots.forEach(function (dot, i) {
    dot.addEventListener("click", function () {
      showSlide(i);
      startSlides();
    });
  });
  startSlides();

  var form = document.querySelector("[data-quote-form]");
  var notice = document.querySelector("[data-form-notice]");
  var errorNotice = document.querySelector("[data-form-error]");
  if (form) {
    var params = new URLSearchParams(window.location.search);
    var buy = params.get("buy");
    if (buy) {
      var serviceField = form.querySelector("#service");
      var isCustom = buy.toLowerCase() === "custom build";
      if (serviceField) {
        var serviceValue = isCustom ? "Website" : "Ready-made website";
        var hasOption = false;
        Array.prototype.forEach.call(serviceField.options, function (option) {
          if (option.value === serviceValue) {
            hasOption = true;
          }
        });
        if (!hasOption) {
          var option = document.createElement("option");
          option.value = serviceValue;
          option.textContent = serviceValue;
          serviceField.appendChild(option);
        }
        serviceField.value = serviceValue;
      }
      if (form.notes && !form.notes.value) {
        form.notes.value = isCustom
          ? "I need a custom website build. Please contact me with a quote."
          : "I want to buy the " + buy + " website ($395, was $599). Please confirm setup and hosting.";
      }
    }
  }
  if (form && notice) {
    form.addEventListener("submit", function (event) {
      event.preventDefault();
      notice.classList.remove("is-visible");
      if (errorNotice) {
        errorNotice.classList.remove("is-visible");
        errorNotice.textContent = "";
      }

      var submit = form.querySelector('button[type="submit"]');
      if (submit) {
        submit.disabled = true;
      }

      var payload = {
        name: form.name.value,
        email: form.email.value,
        phone: form.phone.value,
        service: form.service.value,
        notes: form.notes.value,
        company_website: form.company_website ? form.company_website.value : ""
      };

      fetch("send.php", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(payload)
      })
        .then(function (response) {
          return response.json().then(function (body) {
            return { ok: response.ok && body.ok, body: body };
          });
        })
        .then(function (result) {
          if (!result.ok) {
            var message = (result.body && result.body.error) || "Could not send the message.";
            if (result.body && result.body.debug) {
              message += "\n\n" + JSON.stringify(result.body.debug, null, 2);
            }
            throw new Error(message);
          }
          form.reset();
          notice.classList.add("is-visible");
          notice.scrollIntoView({ behavior: "smooth", block: "center" });
        })
        .catch(function (err) {
          if (errorNotice) {
            errorNotice.textContent = err.message || "Could not send the message. Call or email us instead.";
            errorNotice.classList.add("is-visible");
            errorNotice.scrollIntoView({ behavior: "smooth", block: "center" });
          }
        })
        .then(function () {
          if (submit) {
            submit.disabled = false;
          }
        });
    });
  }
})();
