document.addEventListener("DOMContentLoaded", () => {

    const slides = document.querySelectorAll(".hero-slide");
    const dots = document.querySelectorAll(".hero-dots span");

    if (!slides.length) return;

    let current = 0;

    function show(index){

        slides.forEach((slide,i)=>{

            slide.classList.toggle("active", i===index);

        });

        dots.forEach((dot,i)=>{

            dot.classList.toggle("active", i===index);

        });

        current=index;

    }

    dots.forEach((dot,i)=>{

        dot.addEventListener("click",()=>{

            show(i);

        });

    });

    setInterval(()=>{

        let next=current+1;

        if(next>=slides.length){

            next=0;

        }

        show(next);

    },5000);

});