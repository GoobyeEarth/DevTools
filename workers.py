import click
from services import BarbaricLoggingInjector


@click.group()
def cmd():
    pass


@cmd.command()
def logging_inject():
    obj = BarbaricLoggingInjector()
    obj.run()


def main():
    cmd()


if __name__ == '__main__':
    main()
