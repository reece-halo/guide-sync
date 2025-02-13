function filterFAQ() {
    let input = document.getElementById("faq-search").value.toLowerCase();
    let faqCards = document.querySelectorAll(".faq-card");
    let guideItems = document.querySelectorAll(".guide-item");

    let faqVisible = new Set(); // Track FAQ lists that contain matching guides

    // Step 1: First, check guides and mark their FAQ parents as visible
    guideItems.forEach((item) => {
        let title = item.getAttribute("data-title");
        let faqParent = item.closest(".faq-card"); // Get the parent FAQ section

        if (title.includes(input) || input === "") {
            item.style.display = "block";
            if (faqParent) faqVisible.add(faqParent);
        } else {
            item.style.display = "none";
        }
    });

    // Step 2: Ensure parent FAQ lists remain visible if a child is visible
    faqCards.forEach((card) => {
        let title = card.getAttribute("data-title");
        let parentFAQ = card.closest(".faq-child-columns")?.closest(".faq-card");

        if (faqVisible.has(card) || title.includes(input) || input === "") {
            card.style.display = "block";
            if (parentFAQ) faqVisible.add(parentFAQ); // Ensure parent stays visible
        } else {
            card.style.display = "none";
        }
    });

    // Step 3: Make sure all FAQ lists in `faqVisible` remain visible
    faqVisible.forEach((faq) => {
        faq.style.display = "block";
    });
}