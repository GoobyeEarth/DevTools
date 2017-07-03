import re


class BarbaricLoggingInjector:
    def run(self):
        file_path = 'sandbox/sample.php'
        appending_log = 'log_error("test");\n'

        pattern = r'if *\('

        fp = open(file_path)
        read_lines = fp.readlines()

        output = ''
        for line in read_lines:
            output += line
            if self.has_if(line):
                output += appending_log

        print(output)

    def has_if(self, line: str) -> bool:
        pattern = r'if *\('
        return re.search(pattern, line) is not None
