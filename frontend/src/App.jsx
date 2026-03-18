import { useEffect, useState } from 'react'
import './App.css'

const backendBaseUrl = (import.meta.env.VITE_BACKEND_URL || '').replace(
  /\/$/,
  '',
)

const buildBackendUrl = (path) => {
  if (!backendBaseUrl) {
    return path
  }
  return `${backendBaseUrl}/${path.replace(/^\/+/, '')}`
}

const faqItems = [
  {
    id: 'collapseOne',
    question: 'How does the offline functionality work?',
    answer:
      'The D-Portal system uses local storage and service workers to cache all necessary resources. Once initially loaded, the system can function without an internet connection, syncing data when connectivity is restored.',
  },
  {
    id: 'collapseTwo',
    question: 'What browsers are supported?',
    answer:
      'D-Portal works best on modern browsers including Chrome, Firefox, Edge, and Safari. For optimal performance, we recommend using the latest version of Safe Examination Browser.',
  },
  {
    id: 'collapseThree',
    question: 'How secure is the exam environment?',
    answer:
      "Our system employs multiple security measures including question randomization, Safe Examination Browser's browser lockdown capabilities, and activity monitoring to ensure exam integrity.",
  },
  {
    id: 'collapseFour',
    question: 'Can I customize the exam interface?',
    answer:
      'Yes, administrators can customize colors, logos, and certain interface elements to match institutional branding through the admin portal.',
  },
  {
    id: 'collapseFive',
    question: 'What types of questions are supported?',
    answer:
      'D-Portal supports multiple question types including multiple choice, true/false, fill-in-the-blank, matching, and short answer questions.',
  },
]

const featureItems = [
  'Offline-first design for reliability',
  'Intuitive interface for all user levels',
  'Comprehensive analytics and reporting',
  'Scalable for institutions of all sizes',
  'Regular updates and feature additions',
]

function App() {
  const [activePage, setActivePage] = useState('home')
  const [navOpen, setNavOpen] = useState(false)
  const [openFaq, setOpenFaq] = useState(null)

  useEffect(() => {
    const updateActivePage = () => {
      const hash = window.location.hash.replace('#', '')
      if (['home', 'faq_page', 'about_page'].includes(hash)) {
        setActivePage(hash)
        return
      }
      setActivePage('home')
    }

    updateActivePage()
    window.addEventListener('hashchange', updateActivePage)
    return () => window.removeEventListener('hashchange', updateActivePage)
  }, [])

  const handleNavClick = (page) => {
    setActivePage(page)
    setNavOpen(false)
  }

  const toggleFaq = (index) => {
    setOpenFaq((current) => (current === index ? null : index))
  }

  return (
    <div className="app-shell">
      <div className="floating-shapes">
        <div className="shape shape-1"></div>
        <div className="shape shape-2"></div>
      </div>

      <nav className="navbar navbar-expand-lg navbar-light bg-transparent py-3">
        <div className="container">
          <a className="navbar-brand" href="#home" onClick={() => handleNavClick('home')}>
            <i className="fas fa-graduation-cap me-2"></i>D-Portal
          </a>
          <button
            className="navbar-toggler"
            type="button"
            aria-controls="navbarNav"
            aria-expanded={navOpen}
            aria-label="Toggle navigation"
            onClick={() => setNavOpen((open) => !open)}
          >
            <span className="navbar-toggler-icon"></span>
          </button>
          <div className={`collapse navbar-collapse ${navOpen ? 'show' : ''}`} id="navbarNav">
            <ul className="navbar-nav ms-auto">
              <li className="nav-item">
                <a
                  className={`nav-link ${activePage === 'home' ? 'active' : ''}`}
                  href="#home"
                  data-page="home"
                  aria-current={activePage === 'home' ? 'page' : undefined}
                  onClick={() => handleNavClick('home')}
                >
                  <i className="fas fa-home me-1"></i> Home
                </a>
              </li>
              <li className="nav-item">
                <a
                  className={`nav-link ${activePage === 'faq_page' ? 'active' : ''}`}
                  href="#faq_page"
                  data-page="faq_page"
                  aria-current={activePage === 'faq_page' ? 'page' : undefined}
                  onClick={() => handleNavClick('faq_page')}
                >
                  <i className="fa fa-question-circle me-1"></i> FAQ
                </a>
              </li>
              <li className="nav-item">
                <a
                  className={`nav-link ${activePage === 'about_page' ? 'active' : ''}`}
                  href="#about_page"
                  data-page="about_page"
                  aria-current={activePage === 'about_page' ? 'page' : undefined}
                  onClick={() => handleNavClick('about_page')}
                >
                  <i className="fas fa-info-circle me-1"></i> About
                </a>
              </li>
            </ul>
          </div>
        </div>
      </nav>

      <div id="app-content">
        <section
          className={`page home-page ${activePage === 'home' ? 'active' : ''}`}
          id="home"
        >
          <div className="hero">
            <div className="container">
              <div className="text-center">
                <h1 className="hero-title">D-Portal CBT System</h1>
                <p className="hero-subtitle">
                  A modern, intuitive platform for conducting computer-based tests in offline
                  environments
                </p>
              </div>

              <div className="row justify-content-center g-4">
                <div className="col-lg-5 col-md-6">
                  <div className="card portal-card h-100 admin">
                    <div className="card-body text-center p-5">
                      <div className="card-icon">
                        <i className="fas fa-user-shield"></i>
                      </div>
                      <h3 className="card-title mb-3">Staff Portal</h3>
                      <p className="card-text mb-4">
                        Manage questions, exams, and analyze student performance with powerful
                        tools.
                      </p>
                      <a href={buildBackendUrl('login.php')} className="btn btn-primary">
                        <i className="fas fa-sign-in-alt me-2"></i>Staff Login
                      </a>
                    </div>
                  </div>
                </div>

                <div className="col-lg-5 col-md-6">
                  <div className="card portal-card h-100 student">
                    <div className="card-body text-center p-5">
                      <div className="card-icon">
                        <i className="fas fa-user-graduate"></i>
                      </div>
                      <h3 className="card-title mb-3">Student Portal</h3>
                      <p className="card-text mb-4">
                        Take exams in a distraction-free environment with intuitive controls.
                      </p>
                      <a href={buildBackendUrl('student/register.php')} className="btn btn-student">
                        <i className="fas fa-sign-in-alt me-2"></i>Student Login
                      </a>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <section
          className={`page faq-page ${activePage === 'faq_page' ? 'active' : ''}`}
          id="faq_page"
        >
          <div className="container py-5">
            <div className="text-center mb-5">
              <h1 className="hero-title">Frequently Asked Questions</h1>
              <p className="hero-subtitle">
                Find answers to common questions about our CBT system
              </p>
            </div>

            <div className="row justify-content-center">
              <div className="col-lg-8">
                <div className="accordion" id="faqAccordion">
                  {faqItems.map((item, index) => {
                    const isOpen = openFaq === index
                    return (
                      <div
                        className="accordion-item mb-3 border-0 rounded-3 overflow-hidden shadow-sm"
                        key={item.id}
                      >
                        <h2 className="accordion-header" id={`heading-${item.id}`}>
                          <button
                            className={`accordion-button ${isOpen ? '' : 'collapsed'}`}
                            type="button"
                            onClick={() => toggleFaq(index)}
                            aria-expanded={isOpen}
                            aria-controls={item.id}
                          >
                            {item.question}
                          </button>
                        </h2>
                        <div
                          id={item.id}
                          className={`accordion-collapse ${isOpen ? 'show' : ''}`}
                          aria-labelledby={`heading-${item.id}`}
                        >
                          <div className="accordion-body">{item.answer}</div>
                        </div>
                      </div>
                    )
                  })}
                </div>
              </div>
            </div>
          </div>
        </section>

        <section
          className={`page about-page ${activePage === 'about_page' ? 'active' : ''}`}
          id="about_page"
        >
          <div className="container py-5">
            <div className="text-center mb-5">
              <h1 className="hero-title">About D-Portal</h1>
              <p className="hero-subtitle">Innovative CBT solutions for modern educational needs</p>
            </div>

            <div className="row align-items-center">
              <div className="col-lg-6 mb-4 mb-lg-0">
                <div className="about-image p-4">
                  <div className="image-wrapper rounded-4 overflow-hidden shadow">
                    <img
                      src="https://images.unsplash.com/photo-1588072432836-e10032774350?ixlib=rb-1.2.1&auto=format&fit=crop&w=800&q=80"
                      alt="Education Technology"
                      className="img-fluid"
                    />
                  </div>
                </div>
              </div>
              <div className="col-lg-6">
                <div className="about-content p-4">
                  <h3 className="mb-4">Our Mission</h3>
                  <p className="mb-4">
                    D-Portal was created to bridge the gap between technology and education in areas
                    with limited or unreliable internet connectivity. Our mission is to provide a
                    robust, user-friendly computer-based testing platform that works seamlessly both
                    online and offline.
                  </p>

                  <h3 className="mb-4">Key Features</h3>
                  <ul className="feature-list mb-4">
                    {featureItems.map((feature) => (
                      <li key={feature}>
                        <i className="fas fa-check-circle text-primary me-2"></i>
                        {feature}
                      </li>
                    ))}
                  </ul>

                  <h3 className="mb-4">The Team</h3>
                  <p>
                    D-Portal is developed and maintained by ImadeTech, a software company
                    specializing in educational technology solutions. Our team consists of
                    experienced developers, educators, and UX designers committed to improving
                    learning experiences through technology.
                  </p>
                </div>
              </div>
            </div>

            <div className="row mt-5">
              <div className="col-12">
                <div className="card features-card border-0 shadow-sm rounded-4 overflow-hidden">
                  <div className="card-body p-5">
                    <div className="row text-center">
                      <div className="col-md-4 mb-4 mb-md-0">
                        <div className="feature-icon mb-3">
                          <i className="fas fa-bolt fa-2x text-primary"></i>
                        </div>
                        <h4>Fast Performance</h4>
                        <p className="mb-0">
                          Optimized for quick loading and smooth operation even on older hardware.
                        </p>
                      </div>
                      <div className="col-md-4 mb-4 mb-md-0">
                        <div className="feature-icon mb-3">
                          <i className="fas fa-shield-alt fa-2x text-primary"></i>
                        </div>
                        <h4>Secure</h4>
                        <p className="mb-0">
                          Multiple layers of security to protect exam integrity and student data.
                        </p>
                      </div>
                      <div className="col-md-4">
                        <div className="feature-icon mb-3">
                          <i className="fas fa-sync-alt fa-2x text-primary"></i>
                        </div>
                        <h4>Auto-Sync</h4>
                        <p className="mb-0">
                          Automatically syncs data when internet connection is available.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>
      </div>

      <footer className="text-center">
        <div className="container">
          <p>&copy; 2025 D-Portal CBT Portal — A subsidiary of <b>I</b>made<b>T</b>ech.</p>
        </div>
      </footer>
    </div>
  )
}

export default App
