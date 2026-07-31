document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll("table").forEach((table) => {
    table.querySelectorAll("tbody tr").forEach((row) => {
      row.style.cursor = "pointer";
    });
  });
});
