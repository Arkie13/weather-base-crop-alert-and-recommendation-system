/**
 * Chart Utilities - Optimized Chart.js Configuration and Helpers
 * Provides shared configurations, export functionality, and performance optimizations
 */

class ChartUtils {
    // Default color palette for consistent theming
    static colors = {
        primary: '#667eea',
        secondary: '#764ba2',
        success: '#28a745',
        warning: '#ffc107',
        danger: '#dc3545',
        info: '#17a2b8',
        purple: '#f093fb',
        pink: '#f5576c',
        blue: '#4facfe',
        cyan: '#00f2fe',
        green: '#43e97b',
        red: '#ff6b6b',
        teal: '#4ecdc4',
        lightBlue: '#45b7d1',
        lightGreen: '#96ceb4',
        yellow: '#feca57',
        magenta: '#ff9ff3',
        indigo: '#54a0ff',
        darkPurple: '#5f27cd',
        // Gradient arrays
        gradients: {
            primary: ['#667eea', '#764ba2'],
            success: ['#43e97b', '#38f9d7'],
            danger: ['#ff6b6b', '#ee5a6f'],
            warning: ['#feca57', '#ff9ff3'],
            info: ['#4facfe', '#00f2fe']
        }
    };

    // Shared default options for all charts
    static getDefaultOptions() {
        const isDarkMode = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        const textColor = isDarkMode ? '#e0e0e0' : '#333';
        const gridColor = isDarkMode ? 'rgba(255, 255, 255, 0.1)' : 'rgba(0, 0, 0, 0.05)';
        const borderColor = isDarkMode ? 'rgba(255, 255, 255, 0.2)' : 'rgba(0, 0, 0, 0.1)';

        return {
            responsive: true,
            maintainAspectRatio: false,
            animation: {
                duration: 1000,
                easing: 'easeInOutQuart'
            },
            plugins: {
                legend: {
                    labels: {
                        color: textColor,
                        font: {
                            size: 11,
                            family: "'Segoe UI', Tahoma, Geneva, Verdana, sans-serif"
                        },
                        padding: 10,
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                tooltip: {
                    backgroundColor: isDarkMode ? 'rgba(0, 0, 0, 0.9)' : 'rgba(0, 0, 0, 0.8)',
                    titleColor: '#fff',
                    bodyColor: '#fff',
                    borderColor: borderColor,
                    borderWidth: 1,
                    padding: 12,
                    cornerRadius: 6,
                    displayColors: true,
                    callbacks: {
                        labelColor: function(context) {
                            return {
                                borderColor: context.dataset.borderColor || context.dataset.backgroundColor,
                                backgroundColor: context.dataset.backgroundColor
                            };
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        color: gridColor,
                        lineWidth: 1
                    },
                    ticks: {
                        color: textColor,
                        font: {
                            size: 10
                        }
                    }
                },
                y: {
                    grid: {
                        color: gridColor,
                        lineWidth: 1
                    },
                    ticks: {
                        color: textColor,
                        font: {
                            size: 10
                        }
                    }
                }
            },
            interaction: {
                intersect: false,
                mode: 'index'
            }
        };
    }

    // Options for doughnut/pie charts
    static getDoughnutOptions(showPercentage = true) {
        const baseOptions = this.getDefaultOptions();
        return {
            ...baseOptions,
            plugins: {
                ...baseOptions.plugins,
                legend: {
                    ...baseOptions.plugins.legend,
                    position: 'bottom'
                },
                tooltip: {
                    ...baseOptions.plugins.tooltip,
                    callbacks: {
                        ...baseOptions.plugins.tooltip.callbacks,
                        label: function(context) {
                            if (showPercentage) {
                                const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                const percentage = total > 0 ? ((context.parsed / total) * 100).toFixed(1) : 0;
                                return `${context.label}: ${context.parsed} (${percentage}%)`;
                            }
                            return `${context.label}: ${context.parsed}`;
                        }
                    }
                },
                datalabels: {
                    display: true,
                    color: '#fff',
                    font: {
                        weight: 'bold',
                        size: 11
                    },
                    formatter: function(value, context) {
                        if (showPercentage) {
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = total > 0 ? ((value / total) * 100).toFixed(0) : 0;
                            return percentage > 5 ? percentage + '%' : ''; // Only show if > 5%
                        }
                        return value > 0 ? value : '';
                    }
                }
            }
        };
    }

    // Options for bar charts
    static getBarOptions(horizontal = false) {
        const baseOptions = this.getDefaultOptions();
        return {
            ...baseOptions,
            indexAxis: horizontal ? 'y' : 'x',
            plugins: {
                ...baseOptions.plugins,
                legend: {
                    ...baseOptions.plugins.legend,
                    display: false
                }
            },
            scales: {
                ...baseOptions.scales,
                [horizontal ? 'x' : 'y']: {
                    ...baseOptions.scales[horizontal ? 'x' : 'y'],
                    beginAtZero: true,
                    ticks: {
                        ...baseOptions.scales[horizontal ? 'x' : 'y'].ticks,
                        stepSize: 1
                    }
                }
            }
        };
    }

    // Options for line charts
    static getLineOptions(fill = false) {
        const baseOptions = this.getDefaultOptions();
        return {
            ...baseOptions,
            plugins: {
                ...baseOptions.plugins,
                legend: {
                    ...baseOptions.plugins.legend,
                    position: 'top'
                },
                tooltip: {
                    ...baseOptions.plugins.tooltip,
                    mode: 'index',
                    intersect: false
                }
            },
            elements: {
                line: {
                    tension: 0.4,
                    fill: fill
                },
                point: {
                    radius: 3,
                    hoverRadius: 5,
                    borderWidth: 2
                }
            },
            scales: {
                ...baseOptions.scales,
                y: {
                    ...baseOptions.scales.y,
                    beginAtZero: true
                }
            }
        };
    }

    // Export chart as image
    static exportChart(chart, filename = 'chart', format = 'png') {
        if (!chart) {
            console.error('Chart instance not provided');
            return;
        }

        const url = chart.toBase64Image(format, 1);
        const link = document.createElement('a');
        link.download = `${filename}.${format}`;
        link.href = url;
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }

    // Export chart as PDF (requires jsPDF)
    static exportChartAsPDF(chart, filename = 'chart', title = '') {
        if (typeof window.jspdf === 'undefined') {
            console.warn('jsPDF not loaded. Install: https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js');
            return;
        }

        const { jsPDF } = window.jspdf;
        const pdf = new jsPDF('landscape', 'mm', 'a4');
        const width = pdf.internal.pageSize.getWidth();
        const height = pdf.internal.pageSize.getHeight();

        if (title) {
            pdf.setFontSize(16);
            pdf.text(title, width / 2, 15, { align: 'center' });
        }

        const imgData = chart.toBase64Image('png', 1);
        const imgWidth = width - 20;
        const imgHeight = (chart.canvas.height * imgWidth) / chart.canvas.width;
        const yPosition = title ? 25 : 10;

        pdf.addImage(imgData, 'PNG', 10, yPosition, imgWidth, imgHeight);
        pdf.save(`${filename}.pdf`);
    }

    // Create gradient background
    static createGradient(ctx, colorArray, direction = 'vertical') {
        const gradient = direction === 'vertical' 
            ? ctx.createLinearGradient(0, 0, 0, 400)
            : ctx.createLinearGradient(0, 0, 400, 0);
        
        colorArray.forEach((color, index) => {
            gradient.addColorStop(index / (colorArray.length - 1), color);
        });
        
        return gradient;
    }

    // Destroy chart safely
    static destroyChart(chartInstance) {
        if (chartInstance && typeof chartInstance.destroy === 'function') {
            chartInstance.destroy();
        }
    }

    // Check if chart canvas exists
    static checkCanvas(canvasId) {
        const canvas = document.getElementById(canvasId);
        if (!canvas) {
            console.warn(`Chart canvas "${canvasId}" not found`);
            return null;
        }
        return canvas;
    }

    // Show no data message
    static showNoDataMessage(canvas, message = 'No data available') {
        if (canvas && canvas.parentElement) {
            canvas.parentElement.innerHTML = `
                <div style="text-align: center; padding: 2rem; color: #6c757d;">
                    <i class="fas fa-chart-line" style="font-size: 2rem; margin-bottom: 1rem; opacity: 0.3;"></i>
                    <p>${message}</p>
                </div>
            `;
        }
    }

    // Validate chart data
    static validateChartData(data, requiredFields = []) {
        if (!data) {
            return { valid: false, message: 'No data provided' };
        }

        if (Array.isArray(data) && data.length === 0) {
            return { valid: false, message: 'Empty data array' };
        }

        if (requiredFields.length > 0 && typeof data === 'object') {
            for (const field of requiredFields) {
                if (!(field in data)) {
                    return { valid: false, message: `Missing required field: ${field}` };
                }
            }
        }

        return { valid: true };
    }

    // Format number with commas
    static formatNumber(num) {
        return num.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    }

    // Format percentage
    static formatPercentage(value, total) {
        if (total === 0) return '0%';
        return ((value / total) * 100).toFixed(1) + '%';
    }

    // Get color from palette by index
    static getColor(index) {
        const palette = [
            this.colors.primary,
            this.colors.secondary,
            this.colors.purple,
            this.colors.pink,
            this.colors.blue,
            this.colors.cyan,
            this.colors.green,
            this.colors.red,
            this.colors.teal,
            this.colors.lightBlue,
            this.colors.lightGreen,
            this.colors.yellow,
            this.colors.magenta,
            this.colors.indigo,
            this.colors.darkPurple
        ];
        return palette[index % palette.length];
    }

    // Generate color array for dataset
    static generateColors(count) {
        return Array.from({ length: count }, (_, i) => this.getColor(i));
    }
}

// Make available globally
window.ChartUtils = ChartUtils;

