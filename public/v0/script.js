document.addEventListener("DOMContentLoaded", () => {
  // Mobile menu toggle
  const mobileMenuBtn = document.querySelector(".mobile-menu-btn")
  const navLinks = document.querySelector(".nav-links")

  mobileMenuBtn.addEventListener("click", function () {
    this.classList.toggle("active")
    navLinks.classList.toggle("active")
  })

  // Close mobile menu when clicking on a link
  const navItems = document.querySelectorAll(".nav-links a")
  navItems.forEach((item) => {
    item.addEventListener("click", () => {
      mobileMenuBtn.classList.remove("active")
      navLinks.classList.remove("active")
    })
  })

  // Active link highlighting based on scroll position
  const sections = document.querySelectorAll("section")
  const navLi = document.querySelectorAll(".nav-links li a")

  window.addEventListener("scroll", () => {
    let current = ""

    sections.forEach((section) => {
      const sectionTop = section.offsetTop
      const sectionHeight = section.clientHeight
      if (pageYOffset >= sectionTop - sectionHeight / 3) {
        current = section.getAttribute("id")
      }
    })

    navLi.forEach((li) => {
      li.classList.remove("active")
      if (li.getAttribute("href") === `#${current}`) {
        li.classList.add("active")
      }
    })

    // Header background change on scroll
    const header = document.querySelector("header")
    if (window.scrollY > 100) {
      header.style.background = "rgba(10, 10, 10, 0.95)"
      header.style.boxShadow = "0 5px 20px rgba(0, 0, 0, 0.1)"
    } else {
      header.style.background = "rgba(10, 10, 10, 0.9)"
      header.style.boxShadow = "none"
    }
  })
})

