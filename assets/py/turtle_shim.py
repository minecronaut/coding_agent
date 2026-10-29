"""
A pure-Python implementation of the `turtle` module.

The browser has no tkinter, so instead of drawing to a window this module
records drawing commands into a list. The page then replays that list onto a
<canvas>. Everything a beginner needs is here; the exotic corners of the real
module (polygons, undo buffers, world coordinates) are accepted and ignored
rather than raising.
"""

import math

__all__ = [
    "Turtle", "Screen", "done", "mainloop", "exitonclick", "bye",
    "forward", "fd", "back", "bk", "backward", "right", "rt", "left", "lt",
    "goto", "setpos", "setposition", "setx", "sety", "setheading", "seth",
    "home", "circle", "dot", "stamp", "clear", "clearscreen", "reset",
    "resetscreen", "penup", "pu", "up", "pendown", "pd", "down", "isdown",
    "pencolor", "fillcolor", "color", "width", "pensize", "speed",
    "showturtle", "st", "hideturtle", "ht", "isvisible", "write",
    "xcor", "ycor", "pos", "position", "heading", "distance", "towards",
    "begin_fill", "end_fill", "filling", "bgcolor", "title", "screensize",
    "setup", "tracer", "update", "mode", "colormode", "shape", "shapesize",
    "begin_poly", "end_poly", "getscreen", "textinput", "numinput",
    "ontimer", "listen", "onkey", "onkeypress", "onkeyrelease", "onclick",
]

_ops = []


def _rgb(color):
    if isinstance(color, (tuple, list)):
        if len(color) == 3:
            r, g, b = (max(0, min(255, int(c * 255)) if (isinstance(c, float) and c <= 1) else max(0, min(255, int(c)))) for c in color)
            return "#%02x%02x%02x" % (r, g, b)
    if isinstance(color, (int, float)):
        v = max(0, min(255, int(color)))
        return "#%02x%02x%02x" % (v, v, v)
    return str(color)


class Turtle:
    def __init__(self, screen=None):
        self.x = 0.0
        self.y = 0.0
        self.angle = 0.0
        self.down = True
        self.pen_color = "black"
        self.fill_color = "black"
        self.pen_width = 1
        self.visible = True
        self.shape_name = "classic"
        self.speed_val = 3
        self._fill_pts = None
        self._poly = None

    # -- movement ---------------------------------------------------
    def _line(self, x1, y1, x2, y2):
        if self.down:
            _ops.append({
                "k": "move", "x1": x1, "y1": y1, "x2": x2, "y2": y2,
                "c": self.pen_color, "w": self.pen_width,
            })
        if self._fill_pts is not None:
            self._fill_pts.append((x2, y2))
        if self._poly is not None:
            self._poly.append((x2, y2))

    def forward(self, distance):
        rad = math.radians(self.angle)
        nx = self.x + distance * math.cos(rad)
        ny = self.y + distance * math.sin(rad)
        self._line(self.x, self.y, nx, ny)
        self.x, self.y = nx, ny
        self._cursor()

    fd = forward

    def back(self, distance):
        self.forward(-distance)

    bk = back
    backward = back

    def right(self, angle):
        self.angle -= angle
        self._cursor()

    rt = right

    def left(self, angle):
        self.angle += angle
        self._cursor()

    lt = left

    def goto(self, x, y=None):
        if y is None:
            x, y = x
        self._line(self.x, self.y, x, y)
        self.x, self.y = float(x), float(y)
        self._cursor()

    setpos = goto
    setposition = goto

    def setx(self, x):
        self.goto(x, self.y)

    def sety(self, y):
        self.goto(self.x, y)

    def setheading(self, angle):
        self.angle = float(angle)
        self._cursor()

    seth = setheading

    def home(self):
        self.goto(0, 0)
        self.setheading(0)

    # -- shape ------------------------------------------------------
    def circle(self, radius, extent=None, steps=None):
        if extent is None:
            extent = 360.0
        r = abs(float(radius))
        if r < 1e-9 or extent == 0:
            return
        if steps is None:
            steps = max(8, int(abs(extent) // 5))
        steps = int(steps)
        if steps <= 0:
            return
        angle = float(extent) / steps
        chord = 2 * r * math.sin(abs(math.radians(angle)) / 2)
        turn = angle if radius > 0 else -angle
        for _ in range(steps):
            self.forward(chord)
            self.left(turn)

    def dot(self, size=None, *color):
        if size is None:
            size = max(4, self.pen_width * 2 + 2)
        c = _rgb(color[0]) if color else self.pen_color
        _ops.append({"k": "dot", "x": self.x, "y": self.y, "r": size / 2, "c": c})

    def stamp(self):
        self.dot(16, self.pen_color)

    # -- pen --------------------------------------------------------
    def penup(self):
        self.down = False

    pu = penup
    up = penup

    def pendown(self):
        self.down = True

    pd = pendown
    down = pendown

    def isdown(self):
        return self.down

    def pencolor(self, *args):
        if not args:
            return self.pen_color
        if len(args) == 1:
            self.pen_color = _rgb(args[0])
        elif len(args) == 3:
            self.pen_color = _rgb(tuple(args))
        return self.pen_color

    def fillcolor(self, *args):
        if not args:
            return self.fill_color
        if len(args) == 1:
            self.fill_color = _rgb(args[0])
        elif len(args) == 3:
            self.fill_color = _rgb(tuple(args))
        return self.fill_color

    def color(self, *args):
        if len(args) == 1:
            self.pencolor(args[0])
            self.fillcolor(args[0])
        elif len(args) == 2:
            self.pencolor(args[0])
            self.fillcolor(args[1])
        elif len(args) == 3:
            self.pencolor(*args)
        return self.pen_color

    def width(self, w=None):
        if w is None:
            return self.pen_width
        self.pen_width = max(1, int(w))

    pensize = width

    def speed(self, s=None):
        if s is None:
            return self.speed_val
        if isinstance(s, str):
            s = {"fastest": 0, "fast": 10, "normal": 6, "slow": 3, "slowest": 1}.get(s, 3)
        self.speed_val = s

    # -- fill -------------------------------------------------------
    def begin_fill(self):
        self._fill_pts = [(self.x, self.y)]

    def end_fill(self):
        if self._fill_pts and len(self._fill_pts) > 2:
            _ops.append({"k": "fill", "pts": [list(p) for p in self._fill_pts], "c": self.fill_color})
        self._fill_pts = None

    def filling(self):
        return self._fill_pts is not None

    def begin_poly(self):
        self._poly = [(self.x, self.y)]

    def end_poly(self):
        self._poly = None

    # -- bookkeeping ------------------------------------------------
    def _cursor(self):
        _ops.append({
            "k": "cursor", "x": self.x, "y": self.y, "h": self.angle,
            "v": self.visible, "c": self.pen_color,
        })

    def clear(self):
        _ops.append({"k": "clear"})

    def reset(self):
        _ops.append({"k": "reset"})
        self.x = 0.0
        self.y = 0.0
        self.angle = 0.0
        self.down = True
        self.pen_color = "black"
        self.fill_color = "black"
        self.pen_width = 1
        self.visible = True
        self._fill_pts = None

    def showturtle(self):
        self.visible = True
        self._cursor()

    st = showturtle

    def hideturtle(self):
        self.visible = False
        self._cursor()

    ht = hideturtle

    def isvisible(self):
        return self.visible

    def write(self, text, font=None, align="left", move=False):
        size = 12
        if isinstance(font, (tuple, list)) and len(font) >= 2:
            try:
                size = int(font[1])
            except (TypeError, ValueError):
                size = 12
        _ops.append({
            "k": "text", "x": self.x, "y": self.y, "t": str(text),
            "c": self.pen_color, "size": size, "a": align or "left",
        })

    def xcor(self):
        return self.x

    def ycor(self):
        return self.y

    def pos(self):
        return (self.x, self.y)

    position = pos

    def heading(self):
        return self.angle

    def distance(self, x, y=None):
        if y is None:
            x, y = x
        return math.hypot(x - self.x, y - self.y)

    def towards(self, x, y=None):
        if y is None:
            x, y = x
        return math.degrees(math.atan2(y - self.y, x - self.x)) % 360

    def shape(self, name=None):
        if name is None:
            return self.shape_name
        self.shape_name = name

    def shapesize(self, *args, **kwargs):
        pass

    def get_screen(self):
        return Screen()

    def setundobuffer(self, n):
        pass

    def undo(self):
        pass

    def setworldcoordinates(self, *args):
        pass


_default = Turtle()


# --- module level wrappers -------------------------------------------------

def _d():
    return _default


def forward(distance): _d().forward(distance)
def fd(distance): _d().forward(distance)
def back(distance): _d().back(distance)
def bk(distance): _d().back(distance)
def backward(distance): _d().back(distance)
def right(angle): _d().right(angle)
def rt(angle): _d().right(angle)
def left(angle): _d().left(angle)
def lt(angle): _d().left(angle)
def goto(x, y=None): _d().goto(x, y)
def setpos(x, y=None): _d().goto(x, y)
def setposition(x, y=None): _d().goto(x, y)
def setx(x): _d().setx(x)
def sety(y): _d().sety(y)
def setheading(a): _d().setheading(a)
def seth(a): _d().setheading(a)
def home(): _d().home()
def circle(radius, extent=None, steps=None): _d().circle(radius, extent, steps)
def dot(size=None, *color): _d().dot(size, *color)
def stamp(): _d().stamp()
def clear(): _d().clear()
def clearscreen(): _d().reset()
def reset(): _d().reset()
def resetscreen(): _d().reset()
def penup(): _d().penup()
def pu(): _d().penup()
def up(): _d().penup()
def pendown(): _d().pendown()
def pd(): _d().pendown()
def down(): _d().pendown()
def isdown(): _d().isdown()
def pencolor(*a): return _d().pencolor(*a)
def fillcolor(*a): return _d().fillcolor(*a)
def color(*a): return _d().color(*a)
def width(w=None): return _d().width(w)
def pensize(w=None): return _d().width(w)
def speed(s=None): return _d().speed(s)
def showturtle(): _d().showturtle()
def st(): _d().showturtle()
def hideturtle(): _d().hideturtle()
def ht(): _d().hideturtle()
def isvisible(): return _d().isvisible()
def write(text, font=None, align="left", move=False): _d().write(text, font, align, move)
def xcor(): return _d().xcor()
def ycor(): return _d().ycor()
def pos(): return _d().pos()
def position(): return _d().pos()
def heading(): return _d().heading()
def distance(x, y=None): return _d().distance(x, y)
def towards(x, y=None): return _d().towards(x, y)
def begin_fill(): _d().begin_fill()
def end_fill(): _d().end_fill()
def filling(): return _d().filling()
def begin_poly(): _d().begin_poly()
def end_poly(): _d().end_poly()
def shape(n=None): return _d().shape(n)
def shapesize(*a, **k): pass
def get_screen(): return Screen()


_screen_bg = "white"
_screen_w = 480
_screen_h = 480


def bgcolor(*args):
    global _screen_bg
    if not args:
        return _screen_bg
    _screen_bg = _rgb(args[0]) if len(args) == 1 else _rgb(tuple(args))
    _ops.append({"k": "bg", "c": _screen_bg})
    return _screen_bg


def title(t=None):
    pass


def screensize(w=None, h=None, *a):
    if w is not None:
        global _screen_w
        _screen_w = w
    if h is not None:
        global _screen_h
        _screen_h = h


def setup(*a, **k):
    pass


def tracer(*a, **k):
    pass


def update():
    pass


def mode(*a):
    pass


def colormode(*a):
    pass


def done():
    pass


def mainloop():
    pass


def exitonclick():
    pass


def bye():
    pass


def ontimer(fn, t=0):
    pass


def listen(*a, **k):
    pass


def onkey(*a, **k):
    pass


def onkeypress(*a, **k):
    pass


def onkeyrelease(*a, **k):
    pass


def onclick(*a, **k):
    pass


def textinput(title, prompt):
    raise RuntimeError(
        "turtle.textinput() is not available here. Use input() and type your answer in the Inputs box."
    )


def numinput(title, prompt, default=None, minval=None, maxval=None):
    raise RuntimeError(
        "turtle.numinput() is not available here. Use input() and type your answer in the Inputs box."
    )


class Screen:
    def __init__(self, *a, **k):
        pass

    def bgcolor(self, *a):
        return bgcolor(*a)

    def setup(self, *a, **k):
        pass

    def title(self, *a):
        pass

    def tracer(self, *a, **k):
        pass

    def update(self):
        pass

    def listen(self, *a, **k):
        pass

    def onkey(self, *a, **k):
        pass

    def onkeypress(self, *a, **k):
        pass

    def onclick(self, *a, **k):
        pass

    def ontimer(self, *a, **k):
        pass

    def mainloop(self):
        pass

    def done(self):
        pass

    def bye(self):
        pass

    def exitonclick(self):
        pass

    def screensize(self, *a, **k):
        pass

    def colormode(self, *a, **k):
        pass

    def setworldcoordinates(self, *a):
        pass


# --- hooks used by the runtime --------------------------------------------

def _reset():
    global _default
    _ops.clear()
    _default = Turtle()
    try:
        _ops.append({"k": "bg", "c": _screen_bg})
        # Draw the turtle sitting at home even if the program never moves it,
        # so a turtle program that does nothing still shows its window.
        _default._cursor()
    except Exception:
        pass


def _take():
    out = list(_ops)
    _ops.clear()
    return out
