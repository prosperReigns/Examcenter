document.addEventListener('DOMContentLoaded', function() {
            // Initial animations
            gsap.from(".navbar", {
                duration: 1,
                y: -50,
                opacity: 0,
                ease: "power3.out"
            });
            
            gsap.from(".hero-title", {
                duration: 1,
                y: 30,
                opacity: 0,
                delay: 0.3,
                ease: "back.out(1.7)"
            });
            
            gsap.from(".hero-subtitle", {
                duration: 1,
                y: 30,
                opacity: 0,
                delay: 0.5,
                ease: "power2.out"
            });
            
            gsap.to(".portal-card", {
                duration: 1,
                y: 0,
                opacity: 1,
                delay: 0.7,
                stagger: 0.2,
                ease: "back.out(1.7)"
            });
            
            gsap.from(".shape-1", {
                duration: 10,
                x: -100,
                y: -100,
                repeat: -1,
                yoyo: true,
                ease: "sine.inOut"
            });
            
            gsap.from(".shape-2", {
                duration: 12,
                x: 100,
                y: 100,
                repeat: -1,
                yoyo: true,
                ease: "sine.inOut",
                delay: 2
            });
            
            // Hover animations for cards
            const cards = document.querySelectorAll('.portal-card');
            cards.forEach(card => {
                card.addEventListener('mouseenter', () => {
                    gsap.to(card, {
                        duration: 0.3,
                        scale: 1.03,
                        boxShadow: "0 25px 50px rgba(0, 0, 0, 0.15)",
                        ease: "power2.out"
                    });
                });
                
                card.addEventListener('mouseleave', () => {
                    gsap.to(card, {
                        duration: 0.3,
                        scale: 1,
                        boxShadow: "0 15px 30px rgba(0, 0, 0, 0.1)",
                        ease: "power2.out"
                    });
                });
            });
        });
    