document.addEventListener("DOMContentLoaded",()=>{

    const items=document.querySelectorAll(

        ".reveal,.reveal-left,.reveal-right,.zoom-in"

    );

    const observer=new IntersectionObserver(entries=>{

        entries.forEach(entry=>{

            if(entry.isIntersecting){

                entry.target.classList.add("active");

            }

        });

    },{

        threshold:.15

    });

    items.forEach(item=>observer.observe(item));

});