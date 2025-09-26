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
              const result = math.evaluate(expr);
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