      // Calculator Functions
      let calcExpression = '';
      function calcAppend(char) {
          try {
              if (char === 'pi') char = 'π';
              if (char === '.' && calcExpression.slice(-1) === '.') return false;
              calcExpression += char;
              updateDisplay();
          } catch (e) {
              console.error('calcAppend error:', e);
              updateDisplay('Error');
          }
      }

      function calcClear() {
          try {
              calcExpression = '';
              updateDisplay('0');
          } catch (e) {
              console.error('calcClear error:', e);
          }
      }

      function calcBackspace() {
          try {
              calcExpression = calcExpression.slice(0, -1);
              updateDisplay(calcExpression || '0');
          } catch (e) {
              console.error('calcBackspace error:', e);
          }
      }

      function calcFunction(func) {
          try {
              const lastChar = calcExpression.slice(-1);
              const isNumberOrClose = /[0-9)]/.test(lastChar);
              if (['sin', 'cos', 'tan'].includes(func)) {
                  calcExpression += `${func}(`;
              } else if (func === 'sqrt') {
                  calcExpression += 'sqrt(';
              } else if (func === 'log') {
                  calcExpression += 'log10(';
              } else if (func === 'pow' && isNumberOrClose) {
                  calcExpression += '^2';
              } else if (func === 'fact' && isNumberOrClose) {
                  calcExpression += '!';
              } else {
                  return;
              }
              updateDisplay();
          } catch (e) {
              console.error('calcFunction error:', e);
              updateDisplay('Error');
          }
      }

      function calcEvaluate() {
          try {
              let expr = calcExpression
                  .replace(/π/g, `${Math.PI}`)
                  .replace(/([0-9.]+)!/g, 'factorial($1)')
                  .replace(/(sin|cos|tan)\(([^)]+)\)/g, (match, func, arg) => `${func}(${arg} * pi / 180)`);
              // Offline-safe calculator evaluator. The input is reduced to a strict
              // mathematical grammar before Function() is used; no identifiers,
              // properties, strings, or arbitrary JavaScript are accepted.
              if (!/^[0-9+\-*/().^!π\s_a-zA-Z]+$/.test(expr)) throw new Error('Invalid characters');
              const normalized = expr
                  .replace(/π/g, 'Math.PI')
                  .replace(/\^/g, '**')
                  .replace(/\bsin\(/g, 'Math.sin(')
                  .replace(/\bcos\(/g, 'Math.cos(')
                  .replace(/\btan\(/g, 'Math.tan(')
                  .replace(/\bsqrt\(/g, 'Math.sqrt(')
                  .replace(/\blog10\(/g, 'Math.log10(')
                  .replace(/factorial\(/g, 'factorial(');
              if (/\b(?!Math\.(sin|cos|tan|sqrt|log10)|factorial\b)[A-Za-z_$][A-Za-z0-9_$]*\b/.test(normalized)) {
                  throw new Error('Invalid function');
              }
              const factorial = (n) => {
                  if (!Number.isFinite(n) || n < 0 || Math.floor(n) !== n || n > 170) throw new Error('Invalid factorial');
                  let r = 1; for (let i = 2; i <= n; i++) r *= i; return r;
              };
              const result = Function('factorial', '"use strict"; return (' + normalized + ');')(factorial);
              if (isNaN(result) || result === Infinity || result === -Infinity) {
                  throw new Error('Invalid result');
              }
              calcExpression = result.toString();
              updateDisplay();
          } catch (e) {
              console.error('calcEvaluate error:', e);
              calcExpression = '';
              updateDisplay('Error');
          }
      }